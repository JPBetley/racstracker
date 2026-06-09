<?php

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Imports\Workflows\RosterImportWorkflow;
use App\Jobs\Imports\ImportStep;
use App\Models\Import;

test('the roster type resolves to the roster workflow and its steps', function () {
    $workflow = ImportType::Roster->workflow();

    expect($workflow)->toBeInstanceOf(RosterImportWorkflow::class);

    $steps = $workflow->steps(new Import);

    expect($steps)->toHaveCount(1)
        ->and($steps[0])->toBeInstanceOf(ImportStep::class);
});

test('import status exposes labels and badge colors', function () {
    expect(ImportStatus::Completed->label())->toBe('Completed')
        ->and(ImportStatus::Failed->color())->toBe('red')
        ->and(ImportStatus::Processing->color())->toBe('blue');
});
