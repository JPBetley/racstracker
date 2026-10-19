<?php

use App\LastWar\Game\Gsl\GslCrypto;

it('encrypts the salt with rsa and the form with aes keyed on md5 hex of the salt', function () {
    $key = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $der = base64_encode(base64_decode(implode('', array_slice(explode("\n", trim(openssl_pkey_get_details($key)['key'])), 1, -1))));

    $crypto = new GslCrypto(GslCrypto::pemFromDer($der));
    $request = $crypto->encryptRequest('uuid=device&zone=APS1', 'abcdefghij0123456789');

    openssl_private_decrypt(GslCrypto::base64UrlDecode($request['uuid']), $salt, $key, OPENSSL_PKCS1_PADDING);
    $form = openssl_decrypt(GslCrypto::base64UrlDecode($request['data']), 'aes-256-ecb', md5($salt), OPENSSL_RAW_DATA);

    expect($salt)->toBe('abcdefghij0123456789')
        ->and($form)->toBe('uuid=device&zone=APS1')
        ->and($request['data'])->not->toContain('=', '+', '/');

    $reply = GslCrypto::base64UrlEncode(openssl_encrypt('{"code":"0"}', 'aes-256-ecb', md5($salt), OPENSSL_RAW_DATA));

    expect($crypto->decryptResponse($reply))->toBe('{"code":"0"}');
});
