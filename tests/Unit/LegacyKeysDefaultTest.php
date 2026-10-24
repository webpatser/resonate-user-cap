<?php

use Webpatser\ResonateUserCap\UserConnectionCounter;

it('leaves legacy key reading off by default, since it scans the whole database per subscribe', function () {
    $counter = new ReflectionClass(UserConnectionCounter::class);
    $parameter = collect($counter->getConstructor()->getParameters())
        ->firstWhere('name', 'legacyKeys');

    $config = file_get_contents(__DIR__.'/../../config/resonate-user-cap.php');

    expect($parameter->getDefaultValue())->toBeFalse()
        ->and($config)->toContain("env('RESONATE_USER_CAP_LEGACY_KEYS', false)");
});
