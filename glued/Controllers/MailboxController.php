<?php

declare(strict_types=1);
namespace Glued\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use Glued\Lib\Sql;
use Opis\JsonSchema\Validator;
use Glued\Lib\Controllers\AbstractService;
use Glued\Lib\Exceptions\ExtendedException;
use \PhpMimeMailParser\Parser;

class MailboxController extends AbstractService
{
    /** @var resource|null */
    private $imap;
    private $mbox;
    protected $validator;

    public function __construct(ContainerInterface $container)
    {
        $this->validator = new Validator();

        // register your schemas under file:///mailbox/
        $resolver = $this->validator->loader()->resolver();
        $resolver->registerPrefix(
            'file:///mailbox/',
            __ROOT__ . '/glued/Config/Schemas'
        );
        parent::__construct($container);
    }


    /**
     * Clean up: close the IMAP stream if still open.
     */
    public function __destruct()
    {
        if (is_resource($this->imap)) {
            imap_close($this->imap);
        }
    }

    public function getMailboxes(Request $request, Response $response): Response
    {
        $db = new Sql($this->pg, 'mail_boxes');
        $q = $request->getQueryParams()['q'] ?? false;
        if ($q) { $db->where('doc::text', 'ILIKE', "%{$q}%"); }
        $data = $db->getAll();
        return $response->withJson($data);
    }

    public function createMailbox(Request $request, Response $response): Response
    {
        $doc = $this->getValidatedRequestBody($request, $response, 'file:///mailbox/mailbox.json');
        $db = new Sql($this->pg, 'mail_boxes');
        $db->upsertIgnore =  ['(nonce)', '(host, username)'];
        $res = $db->upsert((array)$doc, true);
        return $response->withJson($res);
    }

    // POST /mailboxes/{id}
    public function patchMailbox(Request $request, Response $response, array $args): Response
    {
        $doc = $this->getValidatedRequestBody($request, $response, 'file:///mailbox/mailboxPatch.json');
        $uuid = $args['uuid'] ?? throw new \Exception('Mailbox UUID required', 400);
        $db = new Sql($this->pg, 'mail_boxes');
        $existing = $db->get($uuid);
        if (!$existing) { throw new \Exception('Mailbox UUID not found', 404); }
        $res = $db->patch($uuid, $doc);
        return $response->withJson($res);
    }

    // DELETE /mailboxes/{id}
    public function deleteMailbox(Request $request, Response $response, array $args): Response
    {
        $uuid = $args['uuid'] ?? null;
        if (!$uuid) { throw new \Exception('Mailbox UUID required', 400); }
        $db = new Sql($this->pg, 'mail_boxes');
        $res = $db->delete('uuid', $uuid);
        return $response->withJson($res);
    }

    public function getMailbox(Request $request, Response $response, array $args): Response
    {
        $uuid = $args['uuid'] ?? throw new \Exception('Mailbox UUID required', 400);
        $db = new Sql($this->pg, 'mail_boxes');
        $res = $db->get($uuid);
        return $response->withJson($res);
    }

    /**
     * Attempt to open an IMAP connection and store the stream.
     *
     * @param  array|object  $data
     * @throws ExtendedException
     */
    public function imap_connect(array|object $data): void
    {
        $data = (object) $data;
        $proto = match ($data->encryption) {
            'ssl' => 'imap/ssl',
            'tls' => 'imap/tls',
            default => 'imap',
        };
        $this->mbox = sprintf('{%s:%d/%s}', $data->host, $data->port, $proto);
        $this->imap = @imap_open($this->mbox, $data->username, $data->password);
        if ($this->imap === false) {
            throw new ExtendedException('Connection failed', 500, imap_last_error());
        }
    }

    public function checkConnectionPost(Request $request, Response $response): Response
    {
        $data = $this->getValidatedRequestBody($request, $response, 'file:///mailbox/mailbox.json');
        $this->imap_connect($data);
        return $response->withJson(['message' => 'Connection successful']);
    }


    public function CheckConnectionGet(Request $request, Response $response): Response {
        return $response->withJson(['message' => 'Post a connection json to check it.']);
    }

    public function checkMailbox(Request $request, Response $response, $args): Response
    {
        $uuid = $args['uuid'] ?? null;
        if (!$uuid) { throw new \Exception('Mailbox UUID required', 400); }
        $db = new Sql($this->pg, 'mail_boxes');
        $data = $db->get($uuid);
        if (!$data) { throw new \Exception('Mailbox not found', 404); }
        $this->imap_connect($data);
        return $response->withJson(['message' => 'Connection successful']);
    }

    public function listFolders(Request $request, Response $response, array $args): Response
    {
        $uuid = $args['uuid'] ?? throw new \Exception('Mailbox UUID required', 400);
        $data = (new Sql($this->pg, 'mail_boxes'))->get($uuid)
            ?? throw new \Exception('Mailbox not found', 404);

        $this->imap_connect($data);
        $raw = imap_list($this->imap, $this->mbox, '*') ?: [];
        $all = array_map(
            fn($line) => preg_replace('#^\{.*\}#', '', $line),
            $raw
        );
        $omit = $cfg['omitFolders'] ?? [];
        $list = array_values(array_diff($all, $omit));
        return $response->withJson(['folders' => $list]);
    }

    /**
     * POST /v1/boxes/{uuid}/sync
     * Sync all messages from every folder into Postgres,
     * skipping already‐cached UIDs and aborting if > $ttl secs.
     */
    public function syncMailbox(Request $request, Response $response, array $args): Response
    {
        // load mailbox config
        ini_set('memory_limit', '512M');
        $uuid = $args['uuid'] ?? throw new \Exception('Mailbox UUID required', 400);
        $mbdb = new Sql($this->pg, 'mail_boxes');
        $cfg = $mbdb->get($uuid) ?? throw new \Exception('Mailbox not found', 404);

        // connect and fetch folders
        $this->imap_connect($cfg);
        $rawFolders = imap_list($this->imap, $this->mbox, '*') ?: [];
        $folders = array_map(
            fn($line) => preg_replace('#^\{.*\}#', '', $line),
            $rawFolders
        );
        $folders = array_values( array_diff( $folders,$cfg['omitFolders'] ?? []));

        // prepare db handler and initiate timeout & progress
        $msgDb = new Sql($this->pg, 'mail_messages');
        $start = time();
        $ttl = 20; // seconds
        $synced = ['folders' => 0, 'messages' => 0];

        foreach ($folders as $folder) {

            // check timeout, set IMAP folder, get all UIDs from IMAP
            if (time() - $start > $ttl) { break; }
            if (!@imap_reopen($this->imap, $this->mbox . $folder)) { continue; } 
            $uids = imap_search($this->imap, 'ALL', SE_UID) ?: [];

            // get all cached UIDs
            $cached = $msgDb
                ->where('mailbox_uuid','=', $uuid)
                ->where('folder','=', $folder)
                ->getAll();
            $seen = array_map(fn($r) => $r['uid'], $cached) ;
            unset($cached);

            // normalize to ints, skip if nothing to do
            $uids = array_map('intval', $uids);
            $seen = array_map('intval', $seen);
            $new = array_diff($uids, $seen);
            if (!$new) { $synced['folders']++; continue; }

            // fetch & store each new message
            foreach ($new as $uid) {

                // exit both loops on timeout
                if (time()-$start > $ttl) { break 2; }

                // initialize parser with message
                $hdrRaw = imap_fetchheader($this->imap, $uid, FT_UID);
                $bodyRaw = imap_body($this->imap, $uid, FT_UID);
                $parser = new Parser();
                $parser->setText($hdrRaw . "\r\n" . $bodyRaw);
                $bodyRawHash = hash('sha256', $bodyRaw);

                // subject
                $subject = $parser->getHeader('subject') ?? '';

                // from
                $from = $parser->getAddresses('from') ?? [];
                array_walk($from, fn(&$e) => $e['address'] = strtolower($e['address']));
                $fromStr = implode(', ', array_column($from, 'address'));  // "alice@example.com, bob@foo.org, …"

                // to
                $to = $parser->getAddresses('to') ?? [];
                array_walk($to, fn(&$e) => $e['address'] = strtolower($e['address']));
                $toStr = implode(', ', array_column($to, 'address'));  // "carol@baz.net, …"

                // datetime
                $dt = ($d = $parser->getHeader('date'))
                    ? (new \DateTimeImmutable(
                        preg_replace('/\s*\([^)]+\)$/', '', $d)
                    ))->format(\DateTime::ATOM)
                    : '';

                // text body
                $textBody = $parser->getMessageBody('text', true) ?: '';

                // synthetic-id, message-id
                $synId = hash('sha256', implode('|', [$dt, $fromStr, $subject, $textBody]));
                $msgId = $parser->getHeader('message-id') ?: "<synthetic-{$synId}>";

                // attachments
                $attachments = [];
                foreach ($parser->getAttachments() as $att) {
                    $attachments[] = [
                        'filename'    => $att->getFilename(),
                        'contentType' => $att->getContentType(),
                        'disposition' => $att->getContentDisposition(),
                        'contentId'   => $att->getContentId(), // inline CID if any
                        //'size'        => $att->getSize(),
                        //'content'     => $att->getContent(),                 // raw binary
                    ];
                    //$att->save('/tmp/attachments/' . $att->getFilename(),);
                    //file_put_contents('/tmp/'.$att->getFilename(), $att->getContent());
                }

                // references, in-reply-to
                $references = ($r = $parser->getHeader('references')) ? preg_split('/\s+/', trim($r)) : [];
                $inReplyTo = $parser->getHeader('in-reply-to') ?: '';
                $candidates = array_values(array_unique(array_filter(
                    [$inReplyTo, ...array_reverse($references)],
                    fn($v) => $v !== ''
                )));

                // conversation-id
                foreach ($candidates as $c) { $msgDb->where('message_id', '=', $c, 'OR'); }
                $r = $msgDb->first()->getAll();
                $conversationId = $r[0]['conversation_id'] ?? $candidates[0] ?? null;

                // build JSON doc
                $doc = [
                    'mailbox_uuid'    => $uuid,
                    'folder'          => $folder,
                    'uid'             => $uid,
                    'message_id'      => $msgId,
                    'in_reply_to'     => $inReplyTo,
                    'references'      => $references,
                    'conversation_id' => $conversationId,
                    'subject'         => $subject,
                    'from'            => $from,
                    'to'              => $to,
                    'fromStr'         => $fromStr,
                    'toStr'           => $toStr,
                    'date'            => $dt,
                    'text_body'       => $textBody,
                    'full_body_hash'  => $bodyRawHash,
                ];

                // insert & skip dup‐message_id
                $upsert = $msgDb->upsert($doc, true);
                $synced['messages']++;

                // cleanup
                unset($hdrRaw, $bodyRaw, $parser, $textBody, $attachments);
                gc_collect_cycles();

            }
            $synced['folders']++;
        }

        // Redirect if timeout
        if ((time() - $start) > $ttl) { return $response->withHeader('Location', $_SERVER['HTTP_X-ORIGINAL-URI'])->withStatus(301); }

        return $response->withJson([
            'foldersProcessed' => $synced['folders'],
            'messagesSynced' => $synced['messages'],
            'timeoutReached' => (time() - $start) > $ttl,
        ]);
    }

}
