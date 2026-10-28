<?php

use App\Support\Judging\JudgingId;
use phpseclib3\Crypt\DES;

/**
 * @return array{roundtrip: array<string, string>, legacy_undecodable: array<string, string>}
 */
function judgingIdVectors(): array
{
    /** @var array{roundtrip: array<string, string>, legacy_undecodable: array<string, string>} $vectors */
    $vectors = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/judging-id-vectors.json'), true);

    return $vectors;
}

it('implements single DES correctly (FIPS 46 / Stinson test vector)', function (): void {
    $des = new DES('ecb');
    $des->setKey((string) hex2bin('133457799BBCDFF1'));
    $des->disablePadding();

    expect(strtoupper(bin2hex($des->encrypt((string) hex2bin('0123456789ABCDEF')))))->toBe('85E813540F0AB405');
});

it('encodes every id exactly like legacy makeId', function (): void {
    foreach (judgingIdVectors()['roundtrip'] as $id => $token) {
        expect(JudgingId::encode((int) $id))->toBe($token, "id {$id}");
    }
});

it('decodes every legacy token back to its id', function (): void {
    foreach (judgingIdVectors()['roundtrip'] as $id => $token) {
        expect(JudgingId::decode($token))->toBe((int) $id, "token {$token}");
    }
});

it('decodes the tokens legacy getId could not decode', function (): void {
    $broken = judgingIdVectors()['legacy_undecodable'];

    expect($broken)->not->toBeEmpty();
    foreach ($broken as $id => $token) {
        expect(JudgingId::encode((int) $id))->toBe($token)
            ->and(JudgingId::decode($token))->toBe((int) $id);
    }
});

it('accepts plain numeric ids like legacy', function (): void {
    expect(JudgingId::decode('123'))->toBe(123);
});

it('rejects malformed, tampered and non-canonical tokens', function (string $token): void {
    expect(JudgingId::decode($token))->toBeNull();
})->with([
    'empty' => [''],
    'zero' => ['0'],
    'too large' => ['99999999999'],
    'slash' => ['IOgXHXRZVkI/'],
    'raw base64 padding' => ['IOgXHXRZVkI='],
    'path traversal' => ['..lfs..lfsetc'],
    'tampered' => ['IOgXHXRZVkJlpct3D'],
    'legacy leq form' => ['IOgXHXRZVkIleq'],
    'truncated' => ['IOgXHXRZ'],
    'non-base64 letters' => ['lpctZZlpctZZlpct'],
]);
