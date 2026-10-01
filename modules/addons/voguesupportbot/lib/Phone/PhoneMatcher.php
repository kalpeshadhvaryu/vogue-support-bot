<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Phone;

final class PhoneMatcher
{
    public function __construct(private readonly PhoneNormalizer $normalizer)
    {
    }

    /**
     * @param list<array{id: int, phonenumber: string}> $clients
     * @return list<int>
     */
    public function matchingIds(string $incoming, array $clients): array
    {
        $ids = [];
        foreach ($clients as $client) {
            $id = $client['id'] ?? 0;
            $phone = $client['phonenumber'] ?? '';
            if (!is_int($id) || $id <= 0 || !is_string($phone)) {
                continue;
            }
            if ($this->normalizer->matches($incoming, $phone)) {
                $ids[] = $id;
            }
        }
        sort($ids);
        return array_values(array_unique($ids));
    }
}
