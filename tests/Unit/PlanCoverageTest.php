<?php

use App\Services\PlanCoverage;

test('representative evidence resolves model quote variants to the authoritative verbatim excerpt', function () {
    $content = '他听见有人在雨里喊，喊的是“还有人没上来——”“名册上还有两个名字——”他拼命想抓住那个没写完的名字。';
    $evidence = '“他听见有人在雨里喊，喊的是‘还有人没上来——’‘名册上还有两个名字——’他拼命想抓住那个没写完的名字。”';

    expect(PlanCoverage::resolveRepresentativeExactEvidence($content, $evidence))
        ->toBe($content);
});
