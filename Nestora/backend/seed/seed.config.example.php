<?php
/**
 * SEED FILE: where you put the details of the admins created by the seed script.
 *
 * 1. Copy this file to  seed.config.php  (same folder). seed.config.php is in .gitignore, never commit it.
 * 2. Fill in the details below.
 * 3. Put the passwords in backend/.env (SEED_ROOT_PASSWORD, SEED_ADMIN2_PASSWORD, ...). Do NOT type passwords here.
 * 4. Run on the SERVER:   php seed/seed.php
 *
 * The FIRST entry is the ROOT admin. Only root can change the app's bank / MoMo details.
 * Other entries are "seeded" admins: they handle money (verifying app payments, paying owners, disputes, refunds)
 * plus everything promoted admins can do. Promoted admins are made later, from the admin pages.
 *
 * Safe to run twice: existing accounts are skipped. To reset the root password, run:  php seed/seed.php --reset-root
 */
return [
    'admins' => [
        [
            'tier'         => 'root',
            'name'         => 'Your Full Name',
            'username'     => 'rootadmin',
            'email'        => 'you@example.com',
            'phone'        => '0240000000',                      // optional, verified automatically for seeded admins
            'password_env' => 'SEED_ROOT_PASSWORD',              // name of the .env variable holding the password
            'photo'        => __DIR__ . '/photos/root.jpg',      // required for every account: put a real photo here
        ],
        // [
        //     'tier'         => 'seeded',
        //     'name'         => 'Second Admin',
        //     'username'     => 'admin_two',
        //     'email'        => 'second@example.com',
        //     'phone'        => '0200000000',
        //     'password_env' => 'SEED_ADMIN2_PASSWORD',
        //     'photo'        => __DIR__ . '/photos/admin2.jpg',
        // ],
    ],

    // The app's own account details that tenants pay into for app payments (only the root admin can change them later).
    'app_payment_details' => [
        'bank_name'      => 'Your Bank',
        'account_name'   => 'Your Company Name',
        'account_number' => '0000000000',
        'momo_network'   => 'MTN',
        'momo_number'    => '0240000000',
        'momo_name'      => 'Your Company Name',
    ],
];
