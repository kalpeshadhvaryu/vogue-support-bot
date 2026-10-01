<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Tests;

use PHPUnit\Framework\TestCase;
use VogueHosting\SupportBot\Knowledge\KnowledgeDocument;
use VogueHosting\SupportBot\Knowledge\KnowledgeSelector;

final class KnowledgeSelectorTest extends TestCase
{
    public function testTitleMatchesOutrankBodyMatches(): void
    {
        $selector = new KnowledgeSelector(4, 1);
        $selected = $selector->select('wordpress hosting', [
            new KnowledgeDocument('kb', '1', 'Email setup', 'This article mentions wordpress once in the body.', ''),
            new KnowledgeDocument('product', '2', 'WordPress Hosting', 'Managed wordpress hosting with daily backups.', ''),
            new KnowledgeDocument('kb', '3', 'Explanation of DNS', 'A plan for name servers.', ''),
        ]);
        self::assertSame('WordPress Hosting', $selected[0]->document->title);
        self::assertGreaterThan($selected[1]->score, $selected[0]->score);
    }

    public function testGreetingsAndStopwordsSelectNothing(): void
    {
        $selector = new KnowledgeSelector();
        $docs = [new KnowledgeDocument('kb', '1', 'Billing help', 'How invoices work.', '')];
        self::assertSame([], $selector->select('hi, can you help me please?', $docs));
    }

    public function testLimitAndStableTitleOrder(): void
    {
        $selector = new KnowledgeSelector(2, 1);
        $docs = [
            new KnowledgeDocument('kb', 'b', 'Beta SSL', 'ssl certificate renewal', ''),
            new KnowledgeDocument('kb', 'a', 'Alpha SSL', 'ssl certificate renewal', ''),
            new KnowledgeDocument('kb', 'c', 'Gamma SSL', 'ssl certificate renewal', ''),
        ];
        $titles = array_map(static fn ($row) => $row->document->title, $selector->select('ssl certificate', $docs));
        self::assertCount(2, $titles);
        self::assertSame(['Alpha SSL', 'Beta SSL'], $titles);
    }

    public function testWordBoundariesAvoidPartialMatches(): void
    {
        $selector = new KnowledgeSelector();
        $selected = $selector->select('plan', [
            new KnowledgeDocument('kb', '1', 'Explanation', 'An explanation of the control panel.', ''),
        ]);
        self::assertSame([], $selected);
    }
}
