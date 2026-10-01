<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Knowledge;

use VogueHosting\SupportBot\Storage\Schema;
use VogueHosting\SupportBot\Whmcs\WhmcsGateway;

final class KnowledgeCache
{
    public function __construct(private readonly WhmcsGateway $gateway)
    {
    }

    /**
     * @return list<KnowledgeDocument>
     */
    public function documents(int $ttlSeconds, bool $force = false): array
    {
        if ($force || $this->isStale($ttlSeconds)) {
            $this->refresh();
        }
        $documents = [];
        $rows = \WHMCS\Database\Capsule::table(Schema::KNOWLEDGE)->orderBy('id')->get();
        foreach ($rows as $row) {
            $documents[] = new KnowledgeDocument(
                (string) $row->source,
                (string) $row->source_key,
                (string) $row->title,
                (string) $row->body,
                (string) $row->url,
            );
        }
        return $documents;
    }

    public function refresh(): int
    {
        $documents = $this->gateway->knowledgeDocuments();
        $now = gmdate('Y-m-d H:i:s');
        \WHMCS\Database\Capsule::connection()->transaction(function () use ($documents, $now): void {
            \WHMCS\Database\Capsule::table(Schema::KNOWLEDGE)->delete();
            foreach ($documents as $document) {
                \WHMCS\Database\Capsule::table(Schema::KNOWLEDGE)->insert([
                    'source' => substr($document->source, 0, 24),
                    'source_key' => substr($document->id, 0, 64),
                    'title' => substr($document->title, 0, 255),
                    'body' => $document->body,
                    'url' => substr($document->url, 0, 512),
                    'refreshed_at' => $now,
                ]);
            }
        });
        return count($documents);
    }

    private function isStale(int $ttlSeconds): bool
    {
        $latest = \WHMCS\Database\Capsule::table(Schema::KNOWLEDGE)->max('refreshed_at');
        if (!is_string($latest) || $latest === '') {
            return true;
        }
        $unix = strtotime($latest . ' UTC');
        if ($unix === false) {
            return true;
        }
        return (time() - $unix) >= $ttlSeconds;
    }
}
