<?php

/*
 * docs/database.md is drawn from the migrated schema. Regenerating it must
 * give exactly the committed file, so a migration can't change the schema
 * without the diagram following (run `php artisan docs:schema`).
 */
test('the committed schema diagram matches the migrations', function () {
    $committed = file_get_contents(base_path('docs/database.md'));
    $fresh = 'storage/framework/testing-database.md';

    $this->artisan('docs:schema', ['--path' => $fresh])->assertSuccessful();

    expect(str_replace("\r\n", "\n", file_get_contents(base_path($fresh))))
        ->toBe(str_replace("\r\n", "\n", $committed));

    unlink(base_path($fresh));
});
