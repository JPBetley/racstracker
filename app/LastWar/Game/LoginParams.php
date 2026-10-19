<?php

namespace App\LastWar\Game;

use App\LastWar\Game\Sfs\SfsObject;

/**
 * Builds the `p` object of the SFS2X zone login, impersonating the Android client.
 *
 * The integrity fields (psh, SecurityCode, OneCode, CoreV) are derived from the
 * login timestamp exactly as the game's Lua does; the server checks them.
 */
final class LoginParams
{
    /**
     * Hex of the APK signing certificate, hashed into the Android `psh` field.
     */
    private const string APK_CERT_HEX = '3082029c30820184a00302010202046fdfb4c4300d06092a864886f70d0101050500300f310d300b060355040a0c044c4359443020170d3233303131363133323131335a180f32303733303130333133323131335a300f310d300b060355040a0c044c43594430820122300d06092a864886f70d01010105000382010f003082010a028201010089ef3aa54cf5a75c83d18c37694482552227620de97dbce3747d0d820b94400a322a65ad61b8360deaf036a9755a4058a6eb01388f6fcb3b061bea3730bc401022471739ebcbe3337c3a67e211c5f7a909397198a6824a0f71a9d8c715575b6529055d922fe7e007ce71b16098c491bdea6f4767ac2e2e7e6cbaa5fbb11c55ffb6561a895c3e7437cb41697db019005473d9f77f4430b163848231ec4903259949b489d5eb436f9cbf4206fcbf040d8c6da6300e30edfeecb5619abfb79dbab2837b4adddba51582a5c85e50568ae32355eba1f974f5567dbb4ac8afdd9ce474f6555e9f5f1085b82072052a08a772db1ee943615b38833dd1e95f0c827ffeed0203010001300d06092a864886f70d01010505000382010100488695eee865bc3992e9dcfa86a19719eb753b7abcd7a5d7ddfd9471229136b37bf08bce8520a98280c0915202ea5a6530f12f81fc6b85769685d8049e73f94d7584ad5e62babc1f9c8c34617f2856d40f7592dad213a0b29edcb5c6ef9179e2d220fd4d83ed42bd6b4f0e430bbcb970db89b352aac9a41f90b15dcb1f76f3efd8e6d104fc23c71c77d50448d9b7666ca182210a33d95b7786a2400f20ff6b9bb33a464bd1134f14eb6a4d6c169d526c2875a5ba0dca2f1f8b8920d1eb3dabfa7977f35fd348eba2a72ee7b052dd8618d5a50b84c25a5e7afdeba271c5eff310d49b934bd8b80724dd7784f35550cfe72c6e0a00140bf50f45acbe1a7eafc2ff';

    private const string SECURITY_CODE_SALT = '4d1c383ccbedf3d98320d6ea06d8dedc';

    /**
     * @param  string  $serverId  The zone without its "APS" prefix.
     */
    public static function build(
        string $deviceId,
        string $gameUid,
        ?string $accessToken,
        string $serverId,
        string $appVersion,
        string $versionCode,
        ?int $cmdBaseTime = null,
        ?string $random32 = null,
    ): SfsObject {
        $cmdBaseTime = (string) ($cmdBaseTime ?? time());
        [$oneCode, $coreV] = self::oneCodeAndCoreV($random32 ?? self::randomDigits(32));
        $packageName = (string) config('services.lastwar.game.package_name');

        $p = (new SfsObject)
            ->putInt('_id', 1)
            ->putInt('netType', 2)
            ->putUtfString('ta', self::analytics($packageName, $appVersion, $versionCode, $serverId))
            ->putUtfString('distinct_id', '')
            ->putUtfString('phone_screen', '1920*1080')
            ->putObject('configVersion', new SfsObject);

        if ($gameUid === '') {
            $p->putInt('country', 1)
                ->putBool('suggestCountry', false)
                ->putUtfString('timeoffset', '0')
                ->putUtfString('gcmRegisterId', '')
                ->putUtfString('referrer', '');
        }

        $p->putUtfString('AndroidID', '')
            ->putUtfString('IMEI', '')
            ->putUtfString('psh', self::psh($cmdBaseTime))
            ->putUtfString('mt', '')
            ->putUtfString('deviceId', $deviceId)
            ->putUtfString('airKey', 'lwDid_'.base64_encode($deviceId))
            ->putUtfString('cmdBaseTime', $cmdBaseTime)
            ->putUtfString('SecurityCode', self::securityCode($cmdBaseTime, $gameUid))
            ->putUtfString('OneCode', $oneCode)
            ->putUtfString('CoreV', $coreV)
            ->putUtfString('googlePlay', '')
            ->putUtfString('androidDid', '')
            ->putUtfString('googleName', '')
            ->putUtfString('deeplinkParams', '')
            ->putUtfString('pfId', '')
            ->putInt('google_available', 0)
            ->putUtfString('fromCountry', 'US')
            ->putUtfString('dataConfigMd5', '')
            ->putUtfString('packageName', $packageName)
            ->putUtfString('packageSign', sha1($packageName))
            ->putUtfString('platform', '1')
            ->putInt('lat', 1)
            ->putUtfString('pf', 'market_global')
            ->putUtfString('firebaseId', '')
            ->putUtfString('afuid', '')
            ->putUtfString('phone_model', 'GoClient')
            ->putInt('configNumber', 0)
            ->putUtfString('gaid', '')
            ->putUtfString('osVersion', 'GoClient/1.0')
            ->putUtfString('parseRegisterId', '')
            ->putUtfString('gameUid', $gameUid)
            ->putUtfString('appVersion', $appVersion)
            ->putUtfString('resVersion', '0')
            ->putUtfString('versionCode', $versionCode)
            ->putUtfString('lang', 'en')
            ->putUtfString('serverId', $serverId)
            ->putInt('gmLogin', 0)
            ->putUtfString('KCPMode', '0')
            ->putInt('forbidden_froce_merge', 1)
            ->putUtfString('shumeiBoxId', '')
            ->putUtfString('simOp', '')
            ->putUtfString('simOpName', '')
            ->putInt('delete_account_status', 0)
            ->putBool('isUseLz4', true);

        if (filled($accessToken)) {
            $p->putUtfString('at', $accessToken);
        }

        return $p;
    }

    public static function psh(string $cmdBaseTime): string
    {
        return md5($cmdBaseTime.self::APK_CERT_HEX);
    }

    public static function securityCode(string $cmdBaseTime, string $gameUid): string
    {
        return md5($cmdBaseTime.self::SECURITY_CODE_SALT.$gameUid);
    }

    /**
     * Derive OneCode and CoreV by interleaving hashes of a random 32-digit string.
     *
     * @return array{0: string, 1: string}
     */
    public static function oneCodeAndCoreV(string $random32): array
    {
        $md5Random = md5($random32);
        $md2 = md5(strrev(base64_encode($random32)));
        $md3 = md5($md2.$random32);
        $oneCode = '';
        $coreV = '';

        for ($i = 0; $i < 32; $i++) {
            $oneCode .= $md5Random[$i].$random32[$i];
            $coreV .= $md3[$i].$md2[$i];
        }

        return [$oneCode, $coreV];
    }

    /**
     * The `ta` analytics blob. An established Android account sending `{}` is rejected
     * with E005, so the platform markers here matter.
     */
    private static function analytics(string $packageName, string $appVersion, string $versionCode, string $serverId): string
    {
        return json_encode([
            '#os' => 'Android',
            '#disk' => '50.0/100.0',
            '#device_id' => '00000000-0000-0000-0000-000000000000',
            '#os_version' => '0.0',
            '#system_language' => 'en',
            '#bundle_id' => $packageName,
            '#carrier' => '',
            '#app_version' => $appVersion,
            '#fps' => 0,
            '#ram' => '0.0/0.0',
            '#simulator' => false,
            '#zone_offset' => 0,
            '#manufacturer' => 'unknown',
            '#screen_height' => 0,
            '#network_type' => 'WIFI',
            '#device_model' => 'Android',
            '#screen_width' => 0,
            '#install_time' => '2000-01-01 00:00:00.000',
            'lw_net' => 'wifi',
            'lw_res_version' => '0.0',
            'lw_buildcode' => $versionCode,
            'lw_line' => '',
            'pd_dl' => 0,
            'lw_ab' => '0',
            'lw_platform' => 'market_global',
            'lw_game_session_id' => '00000000-0000-0000-0000-000000000000',
            'lw_first_launch' => false,
            'lw_alliance_id' => '00000000000000000000000000000000',
            'lw_device_id' => '',
            'lw_device_level' => 0,
            'lw_main_level' => 0,
            'lw_shumei_id' => '',
            'lw_airKey' => '',
            'lw_version' => $appVersion,
            'lw_power' => 0,
            'lw_zone' => 'APS'.$serverId,
        ], JSON_UNESCAPED_SLASHES);
    }

    private static function randomDigits(int $length): string
    {
        $digits = '';

        for ($i = 0; $i < $length; $i++) {
            $digits .= random_int(0, 9);
        }

        return $digits;
    }
}
