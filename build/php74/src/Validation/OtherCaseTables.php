<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Parser\Validation;

/**
 * The other case of each character in the Unicode tables of PCRE2
 * (pcre2_ucd.c), as tests/Tools/write_other_cases.php writes them from the
 * release sources: never edited by hand.
 *
 * @internal
 */
final class OtherCaseTables
{
    /**
     * The first release of each table, from the newest.
     */
    public const RELEASES = [
        '10.48' => '17.0.0',
        '10.45' => '16.0.0',
        '10.43' => '15.0.0',
        '10.39' => '14.0.0',
        '10.35' => '13.0.0',
        '10.34' => '12.1.0',
        '10.32' => '11.0.0',
    ];

    /**
     * Each table by the Unicode version it ships: the one it builds on,
     * the runs of characters whose other case it adds or changes, each as
     * "first:count:distance" in hexadecimal, a distance of 0 for a run of
     * pairs, each the other case of the other; and the characters whose
     * other case it drops.
     */
    public const TABLES = [
        '11.0.0' => [
            'base' => null,
            'add' => '41:1a:20 61:1a:-20 b5:1:307 c0:17:20 d8:7:20 df:1:1dbf e0:17:-20 f8:7:-20 ff:1:79 100:30:0 132:6:0 139:10:0'
                .' 14a:2e:0 178:1:-79 179:6:0 17f:1:-10c 180:1:c3 181:1:d2 182:4:0 186:1:ce 187:2:0 189:2:cd 18b:2:0 18e:1:4f'
                .' 18f:1:ca 190:1:cb 191:2:0 193:1:cd 194:1:cf 195:1:61 196:1:d3 197:1:d1 198:2:0 19a:1:a3 19c:1:d3 19d:1:d5'
                .' 19e:1:82 19f:1:d6 1a0:6:0 1a6:1:da 1a7:2:0 1a9:1:da 1ac:2:0 1ae:1:da 1af:2:0 1b1:2:d9 1b3:4:0 1b7:1:db'
                .' 1b8:2:0 1bc:2:0 1bf:1:38 1c4:1:2 1c5:1:1 1c6:1:-2 1c7:1:2 1c8:1:1 1c9:1:-2 1ca:1:2 1cb:1:1 1cc:1:-2'
                .' 1cd:10:0 1dd:1:-4f 1de:12:0 1f1:1:2 1f2:1:1 1f3:1:-2 1f4:2:0 1f6:1:-61 1f7:1:-38 1f8:28:0 220:1:-82 222:12:0'
                .' 23a:1:2a2b 23b:2:0 23d:1:-a3 23e:1:2a28 23f:2:2a3f 241:2:0 243:1:-c3 244:1:45 245:1:47 246:a:0 250:1:2a1f 251:1:2a1c'
                .' 252:1:2a1e 253:1:-d2 254:1:-ce 256:2:-cd 259:1:-ca 25b:1:-cb 25c:1:a54f 260:1:-cd 261:1:a54b 263:1:-cf 265:1:a528 266:1:a544'
                .' 268:1:-d1 269:1:-d3 26a:1:a544 26b:1:29f7 26c:1:a541 26f:1:-d3 271:1:29fd 272:1:-d5 275:1:-d6 27d:1:29e7 280:1:-da 283:1:-da'
                .' 287:1:a52a 288:1:-da 289:1:-45 28a:2:-d9 28c:1:-47 292:1:-db 29d:1:a515 29e:1:a512 345:1:74 370:4:0 376:2:0 37b:3:82'
                .' 37f:1:74 386:1:26 388:3:25 38c:1:40 38e:2:3f 391:11:20 3a3:9:20 3ac:1:-26 3ad:3:-25 3b1:8:-20 3b9:1:-74 3ba:2:-20'
                .' 3bc:1:-307 3bd:5:-20 3c2:1:1 3c3:9:-20 3cc:1:-40 3cd:2:-3f 3cf:1:8 3d0:1:-1e 3d1:1:-19 3d5:1:-f 3d6:1:-16 3d7:1:-8'
                .' 3d8:18:0 3f0:1:-36 3f1:1:-30 3f2:1:7 3f3:1:-74 3f4:1:-3c 3f5:1:-40 3f7:2:0 3f9:1:-7 3fa:2:0 3fd:3:-82 400:10:50'
                .' 410:20:20 430:20:-20 450:10:-50 460:22:0 48a:36:0 4c0:1:f 4c1:e:0 4cf:1:-f 4d0:60:0 531:26:30 561:26:-30 10a0:26:1c60'
                .' 10c7:1:1c60 10cd:1:1c60 10d0:2b:bc0 10fd:3:bc0 13a0:50:97d0 13f0:6:8 13f8:6:-8 1c80:1:-184e 1c81:1:-184d 1c82:1:-1844 1c83:2:-1842 1c85:1:-1843'
                .' 1c86:1:-183c 1c87:1:-1824 1c88:1:89c3 1c90:2b:-bc0 1cbd:3:-bc0 1d79:1:8a04 1d7d:1:ee6 1e00:96:0 1e9b:1:-3a 1e9e:1:-1dbf 1ea0:60:0 1f00:8:8'
                .' 1f08:8:-8 1f10:6:8 1f18:6:-8 1f20:8:8 1f28:8:-8 1f30:8:8 1f38:8:-8 1f40:6:8 1f48:6:-8 1f51:1:8 1f53:1:8 1f55:1:8'
                .' 1f57:1:8 1f59:1:-8 1f5b:1:-8 1f5d:1:-8 1f5f:1:-8 1f60:8:8 1f68:8:-8 1f70:2:4a 1f72:4:56 1f76:2:64 1f78:2:80 1f7a:2:70'
                .' 1f7c:2:7e 1f80:8:8 1f88:8:-8 1f90:8:8 1f98:8:-8 1fa0:8:8 1fa8:8:-8 1fb0:2:8 1fb3:1:9 1fb8:2:-8 1fba:2:-4a 1fbc:1:-9'
                .' 1fbe:1:-1c05 1fc3:1:9 1fc8:4:-56 1fcc:1:-9 1fd0:2:8 1fd8:2:-8 1fda:2:-64 1fe0:2:8 1fe5:1:7 1fe8:2:-8 1fea:2:-70 1fec:1:-7'
                .' 1ff3:1:9 1ff8:2:-80 1ffa:2:-7e 1ffc:1:-9 2126:1:-1d5d 212a:1:-20bf 212b:1:-2046 2132:1:1c 214e:1:-1c 2160:10:10 2170:10:-10 2183:2:0'
                .' 24b6:1a:1a 24d0:1a:-1a 2c00:2f:30 2c30:2f:-30 2c60:2:0 2c62:1:-29f7 2c63:1:-ee6 2c64:1:-29e7 2c65:1:-2a2b 2c66:1:-2a28 2c67:6:0 2c6d:1:-2a1c'
                .' 2c6e:1:-29fd 2c6f:1:-2a1f 2c70:1:-2a1e 2c72:2:0 2c75:2:0 2c7e:2:-2a3f 2c80:64:0 2ceb:4:0 2cf2:2:0 2d00:26:-1c60 2d27:1:-1c60 2d2d:1:-1c60'
                .' a640:a:0 a64a:1:1 a64b:1:-89c3 a64c:22:0 a680:1c:0 a722:e:0 a732:3e:0 a779:4:0 a77d:1:-8a04 a77e:a:0 a78b:2:0 a78d:1:-a528'
                .' a790:4:0 a796:14:0 a7aa:1:-a544 a7ab:1:-a54f a7ac:1:-a54b a7ad:1:-a541 a7ae:1:-a544 a7b0:1:-a512 a7b1:1:-a52a a7b2:1:-a515 a7b3:1:3a0 a7b4:6:0'
                .' ab53:1:-3a0 ab70:50:-97d0 ff21:1a:20 ff41:1a:-20 10400:28:28 10428:28:-28 104b0:24:28 104d8:24:-28 10c80:33:40 10cc0:33:-40 118a0:20:20 118c0:20:-20'
                .' 16e40:20:20 16e60:20:-20 1e900:22:22 1e922:22:-22',
            'drop' => '',
        ],
        '12.1.0' => [
            'base' => '11.0.0',
            'add' => '282:1:a543 1d8e:1:8a38 a794:1:30 a7ba:6:0 a7c2:2:0 a7c4:1:-30 a7c5:1:-a543 a7c6:1:-8a38',
            'drop' => '',
        ],
        '13.0.0' => [
            'base' => '12.1.0',
            'add' => 'a7c7:4:0 a7f5:2:0',
            'drop' => '',
        ],
        '14.0.0' => [
            'base' => '13.0.0',
            'add' => '2c2f:1:30 2c5f:1:-30 a7c0:2:0 a7d0:2:0 a7d6:4:0 10570:b:27 1057c:f:27 1058c:7:27 10594:2:27 10597:b:-27 105a3:f:-27 105b3:7:-27'
                .' 105bb:2:-27',
            'drop' => '',
        ],
        '15.0.0' => [
            'base' => '14.0.0',
            'add' => '',
            'drop' => '17f 212a',
        ],
        '16.0.0' => [
            'base' => '15.0.0',
            'add' => '19b:1:a641 264:1:a567 390:1:1c43 3b0:1:1c33 1c89:2:0 1fd3:1:-1c43 1fe3:1:-1c33 a7cb:1:-a567 a7cc:2:0 a7da:2:0 a7dc:1:-a641 fb05:2:0'
                .' 10d50:16:20 10d70:16:-20',
            'drop' => '',
        ],
        '17.0.0' => [
            'base' => '16.0.0',
            'add' => 'a7ce:2:0 a7d2:4:0 16ea0:19:1b 16ebb:19:-1b',
            'drop' => '',
        ],
    ];
}
