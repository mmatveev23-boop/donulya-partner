# API авторизации через мессенджеры

## Файлы

| Файл | Назначение |
|------|-----------|
| `session.php` | API сессий: создание (POST), поллинг статуса (GET), подтверждение (POST ?action=confirm) |
| `webhook.php` | Обработчик входящих сообщений от Salebot. Логика: определение session_id → запрос телефона → подтверждение сессии |
| `data/` | Хранение сессий (JSON-файлы), логи, TG-токен. Не коммитится в git |

## Как работает

```
Пользователь на сайте (v2.html)
    ↓ нажимает кнопку TG/VK/MAX
    ↓ генерируется session_id (8 символов)
    ↓ POST /api/session.php — создаёт сессию
    ↓ открывает deep-link на бота
    ↓
Бот в мессенджере (через Salebot)
    ↓ Salebot пересылает сообщение на webhook.php
    ↓ webhook.php определяет session_id:
    ↓   TG: из client.tag (/start параметр)
    ↓   VK/MAX: из client.variables.session_id (ref-link)
    ↓ Отправляет приветствие + запрос телефона
    ↓   TG: кнопка «Отправить номер» (request_contact через Bot API)
    ↓   VK/MAX: текстом
    ↓
Пользователь отправляет телефон
    ↓ webhook.php → POST /api/session.php?action=confirm
    ↓ Бот присылает ссылку на ?step=5
    ↓
Сайт (поллинг каждые 2 сек)
    ↓ GET /api/session.php?session_id=XXX → status: "ok"
    ↓ Автопереход на экран обучения
```

## Конфиг на сервере

- **Webhook URL в Salebot:** `https://donula.online/partners/api/webhook.php`
- **Salebot проект:** #787447
- **API-ключ Salebot:** в webhook.php (константа `SALEBOT_API_KEY`)
- **TG-токен бота:** в `data/.tg_bot_token` (не в git)
- **Данные сессий:** в `data/sessions/*.json` (TTL 72 часа, автоочистка)

## Deep-link ссылки (в v2.html)

```javascript
const AUTH_LINKS = {
  telegram: (sid) => 'https://t.me/partners_donulya_bot?start=' + sid,
  vk:       (sid) => 'https://salebot.pro/ref/06f426980750dcb43bbda9025c50c01a?session_id=' + sid,
  max:      (sid) => 'https://s.salebot.pro/06f426980750dcb43bbda9025c50c01a_20?session_id=' + sid,
};
```

## Деплой

Файлы лежат на сервере 87.228.88.163 в `/var/www/donula.online/partners/`.
PHP 8.3 + FPM, nginx уже настроен.

```bash
scp api/session.php api/webhook.php root@87.228.88.163:/var/www/donula.online/partners/api/
scp v2.html root@87.228.88.163:/var/www/donula.online/partners/index.html
```
