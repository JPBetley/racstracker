<?php

use App\LastWar\Game\LoginParams;

it('derives the integrity fields like the game client', function () {
    expect(LoginParams::securityCode('1700000000', 'guest123'))->toBe('da23365e5910b2cf3db45f43fe970809')
        ->and(LoginParams::securityCode('1700000000', ''))->toBe('724c23edce38133f6a71e64196441746')
        ->and(LoginParams::psh('1700000000'))->toBe('0028a51a469d640c9900bbbc44125e78')
        ->and(LoginParams::oneCodeAndCoreV('01234567890123456789012345678901'))->toBe([
            '209182b3f405169778a90051124394e5366768b960b1b26314853657a819c011',
            '4b747073d2427e77a8936943ea901e369b335762b13ea820d26e7533b4ea3589',
        ]);
});

it('builds the android login parameters', function () {
    $params = LoginParams::build(
        deviceId: 'device_n3d',
        gameUid: '1001',
        accessToken: 'token',
        serverId: '783',
        appVersion: '1.0.364',
        versionCode: '1892',
        cmdBaseTime: 1_700_000_000,
    );

    expect($params->getString('cmdBaseTime'))->toBe('1700000000')
        ->and($params->getString('SecurityCode'))->toBe(LoginParams::securityCode('1700000000', '1001'))
        ->and($params->getString('psh'))->toBe('0028a51a469d640c9900bbbc44125e78')
        ->and($params->getString('at'))->toBe('token')
        ->and($params->getString('airKey'))->toBe('lwDid_'.base64_encode('device_n3d'))
        ->and($params->getString('packageSign'))->toBe(sha1('com.fun.lastwar.gp'))
        ->and($params->getString('platform'))->toBe('1')
        ->and($params->getString('serverId'))->toBe('783')
        ->and($params->has('country'))->toBeFalse()
        ->and(json_decode($params->getString('ta'), true))->toMatchArray([
            '#os' => 'Android',
            '#bundle_id' => 'com.fun.lastwar.gp',
            'lw_platform' => 'market_global',
            'lw_zone' => 'APS783',
        ]);
});

it('adds the first-login fields for a guest and omits an empty token', function () {
    $params = LoginParams::build('device_n3d', '', null, '783', '1.0.364', '1892');

    expect($params->getInt('country'))->toBe(1)
        ->and($params->has('at'))->toBeFalse();
});
