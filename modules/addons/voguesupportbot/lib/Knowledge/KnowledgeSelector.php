<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Knowledge;

/**
 * Cheap keyword ranker. No embeddings and no external search service.
 */
final class KnowledgeSelector
{
    /** @var array<string, true> */
    private const STOPWORDS = [
        'a' => true, 'an' => true, 'the' => true, 'and' => true, 'or' => true,
        'to' => true, 'of' => true, 'for' => true, 'in' => true, 'on' => true,
        'at' => true, 'is' => true, 'it' => true, 'my' => true, 'our' => true,
        'we' => true, 'you' => true, 'your' => true, 'with' => true, 'from' => true,
        'how' => true, 'what' => true, 'when' => true, 'where' => true, 'why' => true,
        'can' => true, 'do' => true, 'does' => true, 'i' => true, 'me' => true,
        'please' => true, 'help' => true, 'about' => true, 'thanks' => true,
        'thank' => true, 'hello' => true, 'hi' => true, 'hey' => true,
    ];

    public function __construct(
        private readonly int $limit = 4,
        private readonly int $minimumScore = 1,
    ) {
    }

    /**
     * @param list<KnowledgeDocument> $documents
     * @return list<ScoredDocument>
     */
    public function select(string $query, array $documents): array
    {
        $terms = $this->terms($query);
        $phrase = $this->phrase($query);
        if ($terms === []) {
            return [];
        }
        $scored = [];
        foreach ($documents as $document) {
            $score = $this->score($terms, $phrase, $document);
            if ($score >= $this->minimumScore) {
                $scored[] = new ScoredDocument($document, $score);
            }
        }
        usort($scored, static function (ScoredDocument $a, ScoredDocument $b): int {
            if ($a->score !== $b->score) {
                return $b->score <=> $a->score;
            }
            return strcmp($a->document->title, $b->document->title);
        });
        return array_slice($scored, 0, max(1, $this->limit));
    }

    /**
     * @param list<string> $terms
     */
    private function score(array $terms, string $phrase, KnowledgeDocument $document): int
    {
        $title = $this->normalize($document->title);
        $body = $this->normalize($document->body);
        $score = 0;
        foreach ($terms as $term) {
            if ($this->containsTerm($title, $term)) {
                $score += 3;
            }
            if ($this->containsTerm($body, $term)) {
                $score += 1;
            }
        }
        if (mb_strlen($phrase) >= 8) {
            if (str_contains($title, $phrase)) {
                $score += 4;
            } elseif (str_contains($body, $phrase)) {
                $score += 2;
            }
        }
        return $score;
    }

    /**
     * @return list<string>
     */
    private function terms(string $query): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $this->normalize($query)) ?: [];
        $terms = [];
        foreach ($tokens as $token) {
            if ($token === '' || mb_strlen($token) < 2 || isset(self::STOPWORDS[$token])) {
                continue;
            }
            $terms[$token] = true;
        }
        return array_keys($terms);
    }

    private function phrase(string $query): string
    {
        $normalized = $this->normalize($query);
        return trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
    }

    private function normalize(string $text): string
    {
        return mb_strtolower($text, 'UTF-8');
    }

    private function containsTerm(string $haystack, string $term): bool
    {
        if ($haystack === '' || $term === '') {
            return false;
        }
        return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u', $haystack) === 1;
    }
}
