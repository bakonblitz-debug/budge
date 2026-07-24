<?php

use App\Models\BankAccount;
use App\Models\ImportBatch;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
    $this->account = BankAccount::factory()->chequing()->create(['user_id' => $this->user->id]);
});

it('shows the import page with bank accounts and available formats', function () {
    $response = $this->get('/transactions/import');

    $response->assertOk();
    $response->assertInertia(
        fn ($page) => $page
            ->component('Transactions/Import')
            ->has('bankAccounts', 1)
            ->has('bankFormats', 3) // NBC bank + NBC credit card + generic AI parser
    );
});

it('imports a bank PDF end-to-end', function () {
    $file = new UploadedFile(
        fixturePath('nbc_bank_sample.pdf'),
        'nbc_april.pdf',
        'application/pdf',
        null,
        true,
    );

    $response = $this->post('/transactions/import', [
        'file' => $file,
        'bank_account_id' => $this->account->id,
        'period_year' => 2026,
        'period_month' => 4,
        'bank_format' => 'nbc_bank_pdf',
    ]);

    $response->assertRedirect();
    expect(ImportBatch::query()->count())->toBe(1);
    expect(Transaction::query()->count())->toBe(36);

    $response->assertSessionHas('type', 'success');
    $response->assertSessionHas(
        'message',
        fn (string $message) => str_contains($message, 'Import complete: 36 transactions imported, 0 updated.')
            && preg_match('/Transactions from [A-Z][a-z]{2} \d{1,2}, \d{4} to [A-Z][a-z]{2} \d{1,2}, \d{4}\./', $message) === 1,
    );
});

it('rejects oversized files (>10MB)', function () {
    $file = UploadedFile::fake()->create('huge.pdf', 11000, 'application/pdf');

    $response = $this->post('/transactions/import', [
        'file' => $file,
        'bank_account_id' => $this->account->id,
        'period_year' => 2026,
        'period_month' => 4,
        'bank_format' => 'nbc_bank_pdf',
    ]);

    $response->assertSessionHasErrors('file');
});

it('rejects unknown formats', function () {
    $file = new UploadedFile(
        fixturePath('nbc_bank_sample.pdf'),
        'x.pdf',
        'application/pdf',
        null,
        true,
    );

    $response = $this->post('/transactions/import', [
        'file' => $file,
        'bank_account_id' => $this->account->id,
        'period_year' => 2026,
        'period_month' => 4,
        'bank_format' => 'fake_format_xyz',
    ]);

    $response->assertSessionHasErrors('bank_format');
});

it('rejects files that arent the right extension', function () {
    $file = UploadedFile::fake()->create('evil.exe', 100, 'application/octet-stream');

    $response = $this->post('/transactions/import', [
        'file' => $file,
        'bank_account_id' => $this->account->id,
        'period_year' => 2026,
        'period_month' => 4,
        'bank_format' => 'nbc_bank_pdf',
    ]);

    $response->assertSessionHasErrors('file');
    expect(ImportBatch::query()->count())->toBe(0);
});

it('cannot import into another users bank account', function () {
    $other = User::factory()->create();
    $otherAccount = BankAccount::factory()->chequing()->create(['user_id' => $other->id]);

    $file = new UploadedFile(
        fixturePath('nbc_bank_sample.pdf'),
        'x.pdf',
        'application/pdf',
        null,
        true,
    );

    $response = $this->post('/transactions/import', [
        'file' => $file,
        'bank_account_id' => $otherAccount->id,
        'period_year' => 2026,
        'period_month' => 4,
        'bank_format' => 'nbc_bank_pdf',
    ]);

    // BelongsToUser global scope should prevent the import from seeing
    // another user's account, so validation should fail or findOrFail throw
    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(0);
});
