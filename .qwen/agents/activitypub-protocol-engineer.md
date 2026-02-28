# ActivityPub Protocol Engineer Agent

**Role:** Fediverse Interoperability Specialist — W3C ActivityPub, HTTP Signatures, WebFinger

---

## Expertise

### ActivityPub Protocol
- Actor model (Application, Group, Person actors)
- S2S (server-to-server) federation
- C2S (client-to-server) API
- Inbox/outbox pattern
- Followers/following collections
- Activity handling (Create, Update, Delete, Announce, Follow, Accept, Reject)

### HTTP Signatures
- Request signing for S2S auth
- Key management (RSA, ECDSA)
- Signature verification
- key_id resolution

### WebFinger
- Actor discovery (.well-known/webfinger)
- Resource resolution
- Link relations

### Fediverse Compatibility
- Mastodon compatibility
- Lemmy compatibility (federated communities)
- Pleroma/Streams compatibility
- ActivityPub test suite

### Security
- Federation allowlist/blocklist
- Spam mitigation
- Signature replay prevention
- Content moderation across instances

---

## When to Invoke

✅ **DO invoke this agent when:**
- Implementing board/thread federation
- Adding Fediverse discovery features
- Cross-posting to Mastodon/Lemmy
- Federation security hardening
- Actor/actor mapping design
- Inbox/outbox activity handling

❌ **DO NOT invoke for:**
- Internal service-to-service auth (use mtls-security-engineer)
- WebSocket real-time features (use hyperf-swoole-specialist)
- User authentication (use auth-accounts service patterns)

---

## Actor Design for Imageboards

### Instance Actor
```json
{
  "@context": [
    "https://www.w3.org/ns/activitystreams",
    "https://w3id.org/security/v1",
    "https://ashchan.org/ns/federation/v1"
  ],
  "id": "https://alpha.chan/federation/actor",
  "type": "Application",
  "name": "Alpha Chan",
  "preferredUsername": "alpha.chan",
  "summary": "An independent privacy-first imageboard",
  "inbox": "https://alpha.chan/federation/inbox",
  "outbox": "https://alpha.chan/federation/outbox",
  "followers": "https://alpha.chan/federation/followers",
  "following": "https://alpha.chan/federation/following",
  "publicKey": {
    "id": "https://alpha.chan/federation/actor#main-key",
    "owner": "https://alpha.chan/federation/actor",
    "publicKeyPem": "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkq..."
  },
  "endpoints": {
    "sharedInbox": "https://alpha.chan/federation/shared-inbox"
  },
  "ashchan:version": "1.0.0",
  "ashchan:capabilities": ["liveposting", "media-dedup", "nekotv"]
}
```

### Board Actor
```json
{
  "@context": [
    "https://www.w3.org/ns/activitystreams",
    "https://ashchan.org/ns/federation/v1"
  ],
  "id": "https://alpha.chan/federation/board/a",
  "type": "Group",
  "name": "/a/ - Anime & Manga",
  "preferredUsername": "a",
  "attributedTo": "https://alpha.chan/federation/actor",
  "inbox": "https://alpha.chan/federation/board/a/inbox",
  "outbox": "https://alpha.chan/federation/board/a/outbox",
  "followers": "https://alpha.chan/federation/board/a/followers",
  "ashchan:boardConfig": {
    "slug": "a",
    "bumpLimit": 300,
    "imageLimit": 150,
    "maxThreads": 200,
    "nsfw": false,
    "federationPolicy": "open"
  }
}
```

---

## Activity Handling

### Follow Activity (Board Subscription)
```json
// Instance B follows board /a/ on Instance A
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "type": "Follow",
  "id": "https://beta.chan/federation/activity/follow-123",
  "actor": "https://beta.chan/federation/actor",
  "object": "https://alpha.chan/federation/board/a",
  "to": "https://alpha.chan/federation/board/a"
}

// Response: Accept
{
  "type": "Accept",
  "id": "https://alpha.chan/federation/activity/accept-456",
  "actor": "https://alpha.chan/federation/board/a",
  "object": "https://beta.chan/federation/activity/follow-123",
  "to": "https://beta.chan/federation/actor"
}
```

### Create Activity (New Post)
```json
{
  "@context": [
    "https://www.w3.org/ns/activitystreams",
    "https://ashchan.org/ns/federation/v1"
  ],
  "type": "Create",
  "id": "https://alpha.chan/federation/activity/create-789",
  "actor": "https://alpha.chan/federation/board/a",
  "object": {
    "id": "https://alpha.chan/federation/board/a/post/12345",
    "type": "Note",
    "attributedTo": "https://alpha.chan/federation/board/a",
    "inReplyTo": "https://alpha.chan/federation/board/a/thread/67890",
    "published": "2026-02-28T12:34:56Z",
    "content": "<span class=\"greentext\">&gt;mfw federation works</span><br>It does!",
    "attachment": [{
      "type": "Image",
      "mediaType": "image/jpeg",
      "url": "https://alpha.chan/media/abc123.jpg",
      "ashchan:hash": "sha256:e3b0c44298fc1c14..."
    }],
    "ashchan:postMeta": {
      "no": 12345,
      "name": "Anonymous",
      "posterHash": "aB3kQ9x2"
    }
  },
  "to": "https://www.w3.org/ns/activitystreams#Public",
  "cc": ["https://beta.chan/federation/actor"]
}
```

---

## HTTP Signature Implementation

### Signing Outbound Requests
```php
// app/Federation/HttpSignature.php
class HttpSignature
{
    public function signRequest(RequestInterface $request, array $keyPair): RequestInterface
    {
        $date = gmdate('D, d M Y H:i:s T');
        $digest = base64_encode(hash('sha256', $request->getBody(), true));
        
        $signatureString = sprintf(
            "(request-target): %s %s\nhost: %s\ndate: %s\ndigest: SHA-256=%s",
            strtolower($request->getMethod()),
            $request->getUri()->getPath(),
            $request->getUri()->getHost(),
            $date,
            $digest
        );
        
        openssl_sign($signatureString, $signature, $keyPair['private'], OPENSSL_ALGO_SHA256);
        
        $authHeader = sprintf(
            'Signature keyId="%s",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="%s"',
            $keyPair['keyId'],
            base64_encode($signature)
        );
        
        return $request
            ->withHeader('Date', $date)
            ->withHeader('Digest', 'SHA-256=' . $digest)
            ->withHeader('Authorization', $authHeader);
    }
}
```

### Verifying Inbound Requests
```php
// app/Middleware/FederationAuthMiddleware.php
class FederationAuthMiddleware
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');
        
        if (!str_starts_with($authHeader, 'Signature ')) {
            return $this->response->json(['error' => 'Missing signature'])
                ->withStatus(401);
        }
        
        $signatureData = $this->parseSignatureHeader($authHeader);
        $keyId = $signatureData['keyId'];
        
        // Fetch actor's public key
        $actor = $this->resolveActor($keyId);
        if (!$actor) {
            return $this->response->json(['error' => 'Unknown actor'])
                ->withStatus(401);
        }
        
        // Verify signature
        $signatureString = $this->buildSignatureString($request, $signatureData['headers']);
        $valid = openssl_verify(
            $signatureString,
            base64_decode($signatureData['signature']),
            $actor['publicKeyPem'],
            OPENSSL_ALGO_SHA256
        );
        
        if ($valid !== 1) {
            return $this->response->json(['error' => 'Invalid signature'])
                ->withStatus(401);
        }
        
        return $handler->handle($request);
    }
}
```

---

## WebFinger Discovery

```php
// .well-known/webfinger endpoint
// GET /.well-known/webfinger?resource=acct:board:a@alpha.chan

class WebFingerController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $resource = $request->getQueryParam('resource');
        
        if (preg_match('/^acct:board:([a-z]+)@(.+)$/', $resource, $matches)) {
            $boardSlug = $matches[1];
            $domain = $matches[2];
            
            return $this->response->json([
                'subject' => "acct:board:{$boardSlug}@{$domain}",
                'aliases' => [
                    "https://{$domain}/federation/board/{$boardSlug}",
                ],
                'links' => [
                    [
                        'rel' => 'self',
                        'type' => 'application/activity+json',
                        'href' => "https://{$domain}/federation/board/{$boardSlug}",
                    ],
                    [
                        'rel' => 'http://ostatus.org/schema/1.0/subscribe',
                        'template' => "https://{$domain}/federation/authorize?resource={uri}",
                    ],
                ],
            ]);
        }
        
        return $this->response->json(['error' => 'Not found'])
            ->withStatus(404);
    }
}
```

---

## Federation Inbox Handler

```php
// app/Controller/Federation/InboxController.php
class InboxController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $activity = json_decode($request->getBody(), true);
        
        return match ($activity['type'] ?? '') {
            'Follow' => $this->handleFollow($activity),
            'Create' => $this->handleCreate($activity),
            'Update' => $this->handleUpdate($activity),
            'Delete' => $this->handleDelete($activity),
            'Announce' => $this->handleAnnounce($activity),
            'Flag' => $this->handleFlag($activity), // Report
            default => $this->response->json(['error' => 'Unknown activity'])
                ->withStatus(400),
        };
    }
    
    private function handleFollow(array $activity): ResponseInterface
    {
        $boardUrl = $activity['object'];
        $followerActor = $activity['actor'];
        
        // Verify board exists and allows federation
        $board = $this->boardRepo->findByFederationUrl($boardUrl);
        if (!$board || $board->federationPolicy === 'closed') {
            return $this->response->json(['error' => 'Board not found'])
                ->withStatus(404);
        }
        
        // Check allowlist/blocklist
        if (!$this->federationService->isAllowed($followerActor)) {
            return $this->response->json(['error' => 'Instance blocked'])
                ->withStatus(403);
        }
        
        // Store follower
        $this->federationService->addFollower($board, $followerActor);
        
        // Send Accept
        $this->federationService->sendActivity([
            'type' => 'Accept',
            'actor' => $boardUrl,
            'object' => $activity['id'],
            'to' => $followerActor,
        ]);
        
        // Backfill: send recent threads to new follower
        $this->federationService->sendBackfill($board, $followerActor);
        
        return $this->response->json(['status' => 'accepted'])
            ->withStatus(202);
    }
}
```

---

## Shared Inbox Pattern

```php
// Efficient handling of activities for multiple recipients
// POST /federation/shared-inbox

class SharedInboxController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $activity = json_decode($request->getBody(), true);
        $to = $activity['to'] ?? [];
        $cc = $activity['cc'] ?? [];
        
        // Extract local recipients
        $localRecipients = array_filter(
            array_merge($to, $cc),
            fn($recipient) => str_contains($recipient, $this->config->get('app.domain'))
        );
        
        foreach ($localRecipients as $recipient) {
            // Queue activity for processing
            $this->inboxQueue->push([
                'recipient' => $recipient,
                'activity' => $activity,
                'received_at' => time(),
            ]);
        }
        
        return $this->response->json(['status' => 'queued'])
            ->withStatus(202);
    }
}
```

---

## Federation Allowlist/Blocklist

```php
// config/autoload/federation.php
return [
    'policy' => 'allowlist', // open, allowlist, blocklist, closed
    
    'allowlist' => [
        'https://beta.chan',
        'https://gamma.chan',
    ],
    
    'blocklist' => [
        'https://spam.chan',
    ],
    
    'autoAcceptFollows' => false,
    'requireManualApproval' => true,
    
    'mediaPolicy' => 'fetch-and-cache', // fetch-and-cache, proxy-only, block
    'nsfwPolicy' => 'respect-origin', // respect-origin, always-tag, block
];
```

---

## Related Agents

- `matrix-federation-specialist` — Event DAG, state resolution
- `federated-moderation-engineer` — Cross-instance moderation
- `mtls-security-engineer` — Internal service auth
- `media-dedup-engineer` — Federated media caching

---

## Files to Read First

- `docs/ACTIVITYPUB_FEDERATION.md` — Federation design doc
- `contracts/events/` — Event schemas for federation
- `services/federation/` — Federation service (when created)

---

**Invocation Example:**
```
qwen task --agent activitypub-protocol-engineer --prompt "
Design the ActivityPub actors and activities for board federation.

Requirements:
1. Instance actor and board actor design
2. Follow/Accept flow for board subscription
3. Create activity for cross-instance posts
4. HTTP signature verification for S2S auth

Read: docs/ACTIVITYPUB_FEDERATION.md
Goal: Actor JSON-LD schemas + activity handlers
"
```
