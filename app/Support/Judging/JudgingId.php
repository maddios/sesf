<?php

namespace App\Support\Judging;

use phpseclib3\Crypt\DES;
use Throwable;

/**
 * The public judging id in /judging/{id}, /judging_answers/{id} and the printed QR codes
 * (legacy CarJudging::makeId / getId).
 *
 * Legacy: openssl_encrypt($id, 'des', 'judging', 0, '12345678'), i.e. single DES in CBC mode with
 * the key zero-padded to 8 bytes, PKCS#7 padding and base64 output; then urlencode() and
 * '%' replaced by 'lpct'. OpenSSL 3 no longer offers DES, so phpseclib's pure-PHP DES is used.
 *
 * Decoding differs from legacy on purpose: legacy also turned 'leq', 'lamp' and 'lfs' back into
 * '=', '&' and '/', which makeId never produces (urlencode escapes them), so any id whose
 * ciphertext contained those letters could not be decoded (25 of the first 300000 ids, e.g. 1121).
 * Here only 'lpct' + hex pair is reversed, and a decoded id is accepted only when it encodes back
 * to the exact input. Plain digits are accepted as the id itself, as in legacy.
 */
final class JudgingId
{
    private const KEY = "judging\0";

    private const IV = '12345678';

    public static function encode(int $id): string
    {
        $cipherText = base64_encode(self::cipher()->encrypt((string) $id));

        return str_replace('%', 'lpct', urlencode($cipherText));
    }

    /** The registration car id, or null when the token is not a valid judging id. */
    public static function decode(string $token): ?int
    {
        if (ctype_digit($token)) {
            return self::positiveInt($token);
        }

        if (preg_match('/\A[A-Za-z0-9]{8,64}\z/', $token) !== 1) {
            return null;
        }

        $cipherText = base64_decode(rawurldecode((string) preg_replace('/lpct(2B|2F|3D)/', '%$1', $token)), true);
        if ($cipherText === false || $cipherText === '' || strlen($cipherText) % 8 !== 0) {
            return null;
        }

        try {
            $plain = self::cipher()->decrypt($cipherText);
        } catch (Throwable) {
            return null;
        }

        $id = ctype_digit($plain) ? self::positiveInt($plain) : null;

        return $id !== null && self::encode($id) === $token ? $id : null;
    }

    private static function positiveInt(string $digits): ?int
    {
        if (strlen($digits) > 10 || (int) $digits < 1 || (int) $digits > 4294967295) {
            return null;
        }

        return (int) $digits;
    }

    private static function cipher(): DES
    {
        $des = new DES('cbc');
        $des->setKey(self::KEY);
        $des->setIV(self::IV);

        return $des;
    }
}
