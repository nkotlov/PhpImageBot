<?php
declare(strict_types=1);

ini_set('display_errors','0');
error_reporting(E_ALL);

require __DIR__.'/vendor/autoload.php';
$config = require __DIR__.'/config.php';
$tg     = new App\TelegramClient($config);

function logit(string $msg, array $data = []): void {
    $line = date('c').' — '.$msg;
    if ($data) {
        $line .= ' '.json_encode($data);
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

            if (in_array($txt, ['/start','/help'], true)) {
                $tg->sendMessage($cid,
                    "Привет! 👋\nПришли изображение (JPEG/PNG/TIFF), а я:\n".
                    "1) Обрежу под стандартный размер\n".
                    "2) Переведу в ч/б (по желанию)\n".
                    "3) Сохраню как файл в формате PNG, JPG или TIFF"
                );
            } else {
                $tg->sendMessage($cid,
                    "Я умею только обрабатывать изображения. Пришлите файл или фото."
                );
            }
            continue;
        }

        if (
            isset($upd['message']['photo'])
            || (
                isset($upd['message']['document'])
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
            $tmpIn = __DIR__."/tmp/in_{$cid}_".time();
            file_put_contents($tmpIn, file_get_contents($url));

            $message = $tg->sendMessage($cid, 'Выберите размер:',
                ['inline_keyboard'=>[[
                    ['text'=>'800×600','callback_data'=>'size:800x600'],
                    ['text'=>'1024×768','callback_data'=>'size:1024x768'],
                    ['text'=>'1920×1080','callback_data'=>'size:1920x1080'],
                ]]]
            );
            $msgId = $message['result']['message_id'] ?? null;

            file_put_contents(__DIR__."/tmp/state_{$cid}", json_encode([
                'in'    => $tmpIn,
                'msgId' => $msgId,
                'w'     => null,
                'h'     => null,
                'gray'  => false,
            ]));
            continue;
        }

        if (isset($upd['callback_query'])) {
            $cid       = $upd['callback_query']['message']['chat']['id'];
            $data      = $upd['callback_query']['data'];
            $cbId      = $upd['callback_query']['id'];
            $stateFile = __DIR__."/tmp/state_{$cid}";

            if (!file_exists($stateFile)) {
                $tg->answerCallbackQuery($cbId);
                $tg->sendMessage($cid, "Сначала пришлите изображение и выберите размер.");
                continue;
            }

            $state = json_decode(file_get_contents($stateFile), true);
            $msgId = $state['msgId'] ?? null;

            $tg->answerCallbackQuery($cbId);

            if ($msgId) {
                $tg->editMessageReplyMarkup($cid, $msgId, []);
            }

            if (str_starts_with($data, 'size:')) {
                [, $sz]         = explode(':', $data, 2);
                [$w, $h]        = explode('x', $sz, 2);
                $state['w']     = (int)$w;
                $state['h']     = (int)$h;

                $message        = $tg->sendMessage($cid, 'Нужен ч/б режим?',
                    ['inline_keyboard'=>[[
                        ['text'=>'Да','callback_data'=>'gray:1'],
                        ['text'=>'Нет','callback_data'=>'gray:0'],
                    ]]]
                );
                $state['msgId'] = $message['result']['message_id'] ?? null;
                file_put_contents($stateFile, json_encode($state));
                continue;
            }

            if (str_starts_with($data, 'gray:')) {
                [, $g]           = explode(':', $data, 2);
                $state['gray']   = ((int)$g === 1);

                $message         = $tg->sendMessage($cid, 'Выберите формат:',
                    ['inline_keyboard'=>[[
                        ['text'=>'PNG','callback_data'=>'fmt:png'],
                        ['text'=>'JPG','callback_data'=>'fmt:jpeg'],
                        ['text'=>'TIFF','callback_data'=>'fmt:tiff'],
                    ]]]
                );
                $state['msgId']  = $message['result']['message_id'] ?? null;
                file_put_contents($stateFile, json_encode($state));
                continue;
            }

            if (str_starts_with($data, 'fmt:')) {
                [, $fmt] = explode(':', $data, 2);
                $w        = (int)$state['w'];
                $h        = (int)$state['h'];

                $ih = new App\ImageHandler($state['in']);
                $ih->crop($w, $h);
                if ($state['gray']) {
                    $ih->toGrayscale();
                }
                $out = __DIR__."/tmp/out_{$cid}.{$fmt}";
                $ih->save($out, $fmt);

                $tg->sendDocument($cid, $out);

                $tg->sendMessage($cid, "Готово! Пришлите новое изображение, если нужно.");

                @unlink($state['in']);
                @unlink($out);
                @unlink($stateFile);
                continue;
            }
        }

        if (isset($upd['message'])) {
            $cid = $upd['message']['chat']['id'];
            $tg->sendMessage($cid,
                "Я умею только обрабатывать изображения. Пришли файл или фото."
            );
        }
    }
}
