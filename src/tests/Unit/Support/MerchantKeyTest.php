<?php

use App\Models\Transaction;
use App\Support\MerchantKey;

/** Build an unsaved Transaction carrying only the fields MerchantKey reads. */
function merchantKeyTransaction(?string $merchantName, string $description): Transaction
{
    $t = new Transaction;
    $t->merchant_name = $merchantName;
    $t->description = $description;

    return $t;
}

it('prefers merchant_name over description, lowercased', function () {
    $t = merchantKeyTransaction('SPOTIFY', 'SPOTIFY P36FB9F285');

    expect(MerchantKey::for($t))->toBe('spotify');
});

it('falls back to description when merchant_name is null', function () {
    $t = merchantKeyTransaction(null, 'NETFLIX.COM');

    expect(MerchantKey::for($t))->toBe('netflix.com');
});

it('strips a trailing geo pair (city + province/country code)', function () {
    $a = merchantKeyTransaction(null, 'IGA SOMEVILLE QC');
    $b = merchantKeyTransaction(null, 'IGA STOCKHOLM SWE');

    expect(MerchantKey::for($a))->toBe('iga')
        ->and(MerchantKey::for($b))->toBe('iga');
});

it('strips mixed alphanumeric reference tokens and pure digit runs anywhere', function () {
    $t = merchantKeyTransaction(null, 'GOODLIFE FITNESS #221 P36FB9F285');

    expect(MerchantKey::for($t))->toBe('goodlife fitness');
});

it('keeps short mixed tokens and plain words so distinct merchants do not collide', function () {
    $a = merchantKeyTransaction(null, '7-ELEVEN');
    $b = merchantKeyTransaction(null, 'A1 AUTO');

    expect(MerchantKey::for($a))->toBe('7-eleven')
        ->and(MerchantKey::for($b))->toBe('a1 auto');
});

it('never reduces to an empty key when everything strips away', function () {
    // A lone geo token with nothing else survives as itself (never emptied).
    $t = merchantKeyTransaction(null, '12345');

    expect(MerchantKey::for($t))->toBe('12345');
});

it('returns an empty key only when there is truly no merchant text', function () {
    $t = merchantKeyTransaction('', '');

    expect(MerchantKey::for($t))->toBe('');
});

it('collapses repeated whitespace before tokenizing', function () {
    $t = merchantKeyTransaction(null, 'UBER   EATS');

    expect(MerchantKey::for($t))->toBe('uber eats');
});

it('produces the SAME key for the same transaction shape regardless of noise variant (parity)', function () {
    // Simulates what RecurringDetector, OneTimePurchaseDetector, and
    // InvestmentScheduleProjector each used to compute independently — now all
    // three delegate to this one helper, so three noisy variants of the same
    // merchant collapse to one identical grouping key.
    $variants = [
        merchantKeyTransaction('SPOTIFY', 'SPOTIFY AB123456 QC'),
        merchantKeyTransaction('SPOTIFY', 'SPOTIFY #4471'),
        merchantKeyTransaction(null, 'SPOTIFY MONTREAL QC'),
    ];

    $keys = array_map(fn (Transaction $t): string => MerchantKey::for($t), $variants);

    expect(array_unique($keys))->toHaveCount(1)
        ->and($keys[0])->toBe('spotify');
});

it('strips a trailing US state code as geo', function () {
    $t = merchantKeyTransaction(null, 'WL *BLIZZARD ENTERTAIN IRVINE CA');

    expect(MerchantKey::for($t))->toBe('wl *blizzard entertain');
});

it('does not treat an unlisted French/English word as geo', function () {
    // "LA" collides with the French article "la" / English "LA" and was
    // deliberately excluded (not confirmed as genuine trailing geo in the
    // data) — it must stay attached, not be stripped.
    $t = merchantKeyTransaction(null, 'EPICERIE MONTREAL LA');

    expect(MerchantKey::for($t))->toBe('epicerie montreal la');
});

it('normalize() only lowercases and collapses whitespace, no geo/noise stripping', function () {
    expect(MerchantKey::normalize('  Goodlife   Fitness #221  '))->toBe('goodlife fitness #221')
        ->and(MerchantKey::normalize('BELL CANADA'))->toBe('bell canada');
});
