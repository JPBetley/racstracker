<?php

use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\Sfs\SfsArray;
use App\LastWar\Game\Sfs\SfsCodec;
use App\LastWar\Game\Sfs\SfsObject;
use App\LastWar\Game\Sfs\SfsType;
use App\LastWar\Game\Sfs\SfsValue;

it('encodes the extension envelope byte for byte', function () {
    $object = (new SfsObject)
        ->putByte('c', 1)
        ->putShort('a', 13)
        ->putObject('p', (new SfsObject)->putUtfString('c', 'al.rank')->putInt('r', -1));

    expect(bin2hex(SfsCodec::encode($object)))->toBe(
        '12'.'0003'
        .'0001'.'63'.'02'.'01'
        .'0001'.'61'.'03'.'000d'
        .'0001'.'70'.'12'.'0002'
        .'0001'.'63'.'08'.'0007'.bin2hex('al.rank')
        .'0001'.'72'.'04'.'ffffffff'
    );
});

it('round-trips every supported type', function () {
    $array = (new SfsArray)
        ->addObject((new SfsObject)->putLong('score', 9_000_000_000))
        ->add(new SfsValue(SfsType::UtfString, 'x'));

    $object = (new SfsObject)
        ->putNull('nothing')
        ->putBool('yes', true)
        ->putByte('byte', 200)
        ->putShort('short', -2)
        ->putInt('int', -123_456)
        ->putLong('long', -9_000_000_000)
        ->putDouble('double', 1.5)
        ->putUtfString('name', 'Ünïcode')
        ->put('text', new SfsValue(SfsType::Text, str_repeat('a', 40_000)))
        ->put('bytes', new SfsValue(SfsType::ByteArray, "\x00\xff"))
        ->put('ints', new SfsValue(SfsType::IntArray, [1, -1]))
        ->put('longs', new SfsValue(SfsType::LongArray, [PHP_INT_MAX]))
        ->put('strings', new SfsValue(SfsType::UtfStringArray, ['E011', 'E005']))
        ->put('bools', new SfsValue(SfsType::BoolArray, [true, false]))
        ->put('float', new SfsValue(SfsType::Float, 0.5))
        ->putArray('list', $array);

    $decoded = SfsCodec::decode(SfsCodec::encode($object));

    expect(SfsCodec::encode($decoded))->toBe(SfsCodec::encode($object))
        ->and($decoded->toArray())->toMatchArray([
            'nothing' => null,
            'yes' => true,
            'byte' => 200,
            'short' => -2,
            'int' => -123_456,
            'long' => -9_000_000_000,
            'name' => 'Ünïcode',
            'bytes' => base64_encode("\x00\xff"),
            'strings' => ['E011', 'E005'],
            'list' => [['score' => 9_000_000_000], 'x'],
        ]);
});

it('reads numbers and strings flexibly', function () {
    $object = (new SfsObject)->putLong('port', 10783)->putUtfString('score', '42')->putInt('day', 3);

    expect($object->getInt('port'))->toBe(10783)
        ->and($object->getInt('score'))->toBe(42)
        ->and($object->getString('day'))->toBe('3')
        ->and($object->getString('missing', 'fallback'))->toBe('fallback');
});

it('rejects truncated and trailing data', function (string $hex) {
    SfsCodec::decode(hex2bin($hex));
})->throws(GameClientException::class)->with([
    'not an object' => ['08000161'],
    'truncated' => ['120001000161'],
    'trailing bytes' => ['120000ff'],
]);
