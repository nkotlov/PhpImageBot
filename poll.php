<?php
declare(strict_types=1);

ini_set('display_errors','0');
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';

$tg = new App\TelegramClient($config);

function logit(string $msg, array $data = []) {
    $line = date('c').' — '.$msg;
    if ($data) {
        $line .= ' ' . json_encode($data);
    }
    file_put_contents(__DIR__.'/tmp/log.txt', $line."\n", FILE_APPEND);
}

$offset = 0;
logit('Bot started (polling)');

while (true) {
    $updates = $tg->getUpdates($offset, 30);
    if (!isset($updates['ok']) || $updates['ok'] !== true) {
        logit('getUpdates failed', $updates);
        sleep(5);
        continue;
    }

    foreach ($updates['result'] as $upd) {
        $offset = $upd['update_id'] + 1;
        logit('Update', $upd);

        if (isset($upd['message']['text'])) {
            $cid = $upd['message']['chat']['id'];
            $txt = $upd['message']['text'];
            if ($txt === '/start' || $txt === '/help') {
                $tg->sendMessage($cid,
                    "Привет! 👋\nПришли фото, я обрежу и переконвертирую:\n".
                    "1) Выбор размера\n2) Ч/Б или цвет\n3) Формат (PNG/JPG/TIFF)"
                );
            } else {
                $tg->sendMessage($cid,
                    "Я пока умею только обрабатывать фото. Пришли изображение!"
                );
            }
            continue;
        }

        if (isset($upd['message']['photo'])
            || (isset($upd['message']['document'])
                && str_starts_with($upd['message']['document']['mime_type'], 'image/')
            )
        ) {
            $cid = $upd['message']['chat']['id'];

            if (isset($upd['message']['photo'])) {
                $p      = end($upd['message']['photo']);
                $fileId = $p['file_id'];
            } else {
                $doc    = $upd['message']['document'];
                $fileId = $doc['file_id'];
            }

            $url   = $tg->getFile($fileId);
            $tmpIn = __DIR__ . "/tmp/in_{$cid}_" . time();
            file_put_contents($tmpIn, file_get_contents($url));

            $kb = ['inline_keyboard'=>[[
                ['text'=>'800×600','callback_data'=>'size:800x600'],
                ['text'=>'1024×768','callback_data'=>'size:1024x768'],
                ['text'=>'1920×1080','callback_data'=>'size:1920x1080'],
            ]]];
            $tg->sendMessage($cid, 'Выберите размер:', $kb);

            file_put_contents(__DIR__ . "/tmp/state_{$cid}", json_encode([
                'in'   => $tmpIn,
                'w'    => null,
                'h'    => null,
                'gray' => false,
            ]));
            continue;
        }

        if (isset($upd['callback_query'])) {
            $cid   = $upd['callback_query']['message']['chat']['id'];
            $data  = $upd['callback_query']['data'];
            $state = json_decode(file_get_contents(__DIR__."/tmp/state_{$cid}"), true);

            if (str_starts_with($data,'size:')) {
                [, $sz]    = explode(':',$data);
                [$w,$h]    = explode('x',$sz);
                $state['w'] = (int)$w;
                $state['h'] = (int)$h;
                file_put_contents(__DIR__."/tmp/state_{$cid}", json_encode($state));

                $kb = ['inline_keyboard'=>[[
                    ['text'=>'Ч/Б','callback_data'=>'gray:1'],
                    ['text'=>'Цветное','callback_data'=>'gray:0'],
                ]]];
                $tg->sendMessage($cid, 'Нужен чёрно‑белый режим?', $kb);
                continue;
            }

            if (str_starts_with($data,'gray:')) {
                [, $g]         = explode(':',$data);
                $state['gray'] = ((int)$g === 1);
                file_put_contents(__DIR__."/tmp/state_{$cid}", json_encode($state));

                $kb = ['inline_keyboard'=>[[
                    ['text'=>'PNG','callback_data'=>'fmt:png'],
                    ['text'=>'JPG','callback_data'=>'fmt:jpeg'],
                    ['text'=>'TIFF','callback_data'=>'fmt:tiff'],
                ]]];
                $tg->sendMessage($cid, 'Выберите формат:', $kb);
                continue;
            }

            if (str_starts_with($data,'fmt:')) {
                [, $fmt] = explode(':',$data);

                $w = (int) $state['w'];
                $h = (int) $state['h'];

                $ih = new App\ImageHandler($state['in']);
                $ih->crop($w, $h);
                if ($state['gray']) {
                    $ih->toGrayscale();
                }

                $out = __DIR__."/tmp/out_{$cid}.{$fmt}";
                $ih->save($out, $fmt);

                if ($fmt === 'tiff') {
                    $tg->sendDocument($cid, $out);
                } else {
                    $tg->sendPhoto($cid, $out);
                }

                @unlink($state['in']);
                @unlink($out);
                @unlink(__DIR__."/tmp/state_{$cid}");
                continue;
            }
        }
    }
}
