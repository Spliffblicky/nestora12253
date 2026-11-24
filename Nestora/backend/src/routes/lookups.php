<?php
declare(strict_types=1);

/* Reference data the app needs for forms: regions/cities/areas, amenities, policies, public settings. */

route('GET', '/lookups', function () {
    require_user(false);
    $regions = rows('SELECT id, name FROM regions ORDER BY name');
    $cities = rows('SELECT id, region_id, name FROM cities WHERE active = 1 ORDER BY name');
    json_out([
        'regions'  => $regions,
        'cities'   => $cities,
        'building_amenities' => amenity_defs('building'),
        'room_amenities'     => amenity_defs('room'),
        'termination_policies' => rows('SELECT id, label, basis, percent, months FROM termination_policies WHERE active = 1 ORDER BY id'),
        'rent_durations' => [6, 12, 24],
        'payers' => ['tenant', 'owner', 'split', 'included_in_rent'],
        'limits' => [
            'building_photos' => (int)setting('max_building_photos', '7'),
            'room_photos'     => (int)setting('max_room_photos', '10'),
            'note_words'      => 10,
            'custom_amenities' => 5,
        ],
        'fee_percent' => (float)setting('platform_fee_percent', '5'),
        'fee_payer'   => setting('fee_payer', 'owner'),
        'tenant_process_days' => (int)setting('tenant_process_days', '5'),
    ]);
});

route('GET', '/areas', function () {
    require_user(false);
    json_out(['areas' => rows("SELECT id, name FROM areas WHERE city_id = ? AND status = 'approved' AND active = 1 ORDER BY name", [in_int('city_id', true)])]);
});
