# Команди

Усе після входу — це команда та відповідь на неї. Ця сторінка описує поверхню команд як ціле: як
дістатися команди, що вона повертає, як позначаються транзакції, як вимкнути винятки і як надіслати
кадр, якого бібліотека не моделює.

Подробиці щодо кожного об'єкта — на власній сторінці: [Домени](domains.md),
[Контакти](contacts.md), [Хости](hosts.md), [Poll](poll.md), [Баланс](balance.md).

## Як дістатися команди

На клієнті висять чотири обробники плюс запит балансу, який є одним методом:

```php
$client->domain();    // EppTools\Command\Domain   — RFC 5731
$client->contact();   // EppTools\Command\Contact  — RFC 5733
$client->host();      // EppTools\Command\Host     — RFC 5732
$client->poll();      // EppTools\Command\Poll     — RFC 5730 §2.9.2.3
$client->balance();   // розширення балансу реєстру — Response, а не обробник
```

Кожен обробник створюється один раз на клієнт і повертається знову за кожного виклику, тож
`$client->domain()->check(…)` усередині циклу не коштує нічого зайвого.

## Уся поверхня

| Метод | Надсилає | Описано в |
|---|---|---|
| `domain()->check(array $names, array $fee = [], ?string $currency = null): Response` | `domain:check`, за потреби з `fee:check` | [Домени](domains.md), [Баланс](balance.md) |
| `domain()->info(string $name, ?string $authInfo = null, string $hosts = 'all'): Response` | `domain:info` | [Домени](domains.md) |
| `domain()->create(string $name, array $options = []): Response` | `domain:create` | [Домени](domains.md) |
| `domain()->update(string $name, array $options = []): Response` | `domain:update` | [Домени](domains.md) |
| `domain()->renew(string $name, string $curExpDate, int $years = 1, string\|array\|null $fee = null): Response` | `domain:renew` | [Домени](domains.md) |
| `domain()->restore(string $name, string\|array\|null $fee = null): Response` | `domain:update` з `rgp:restore` (RFC 3915) | [Домени](domains.md) |
| `domain()->delete(string $name): Response` | `domain:delete` | [Домени](domains.md) |
| `domain()->transfer(string $op, string $name, ?string $authInfo = null, ?int $years = null, string\|array\|null $fee = null): Response` | `domain:transfer` | [Домени](domains.md) |
| `domain()->createBuilder(string $name): DomainCreateBuilder` | нічого до `send()` | [Білдери](builders.md) |
| `domain()->updateBuilder(string $name): DomainUpdateBuilder` | нічого до `send()` | [Білдери](builders.md) |
| `contact()->check(array $ids): Response` | `contact:check` | [Контакти](contacts.md) |
| `contact()->info(string $id, ?string $authInfo = null): Response` | `contact:info` | [Контакти](contacts.md) |
| `contact()->create(string $id, array $options = []): Response` | `contact:create` | [Контакти](contacts.md) |
| `contact()->createAuto(array $options = []): Response` | `contact:create` із зарезервованим ідентифікатором `Contact::AUTO_ID` | [Контакти](contacts.md) |
| `contact()->update(string $id, array $options = []): Response` | `contact:update` | [Контакти](contacts.md) |
| `contact()->delete(string $id): Response` | `contact:delete` | [Контакти](contacts.md) |
| `contact()->transfer(string $op, string $id, ?string $authInfo = null): Response` | `contact:transfer` | [Контакти](contacts.md) |
| `contact()->createBuilder(string $id, string $email): ContactCreateBuilder` | нічого до `send()` | [Білдери](builders.md) |
| `contact()->updateBuilder(string $id): ContactUpdateBuilder` | нічого до `send()` | [Білдери](builders.md) |
| `host()->check(array $names): Response` | `host:check` | [Хости](hosts.md) |
| `host()->info(string $name): Response` | `host:info` | [Хости](hosts.md) |
| `host()->create(string $name, array $addresses = []): Response` | `host:create` | [Хости](hosts.md) |
| `host()->update(string $name, array $options = []): Response` | `host:update` | [Хости](hosts.md) |
| `host()->delete(string $name, bool $force = false): Response` | `host:delete`, за потреби з розширенням примусового видалення від реєстру | [Хости](hosts.md) |
| `host()->updateBuilder(string $name): HostUpdateBuilder` | нічого до `send()` | [Білдери](builders.md) |
| `poll()->request(): Response` | `<poll op="req">` | [Poll](poll.md) |
| `poll()->ack(string $messageId): Response` | `<poll op="ack">` | [Poll](poll.md) |
| `poll()->drain(callable $handler, int $limit = 0): int` | request/ack у циклі | [Poll](poll.md) |
| `balance(): Response` | `balance:info` | [Баланс](balance.md) |
| `request(string\|Frame $frame): Response` | те, що ви побудували | [нижче](#власні-кадри) |
| `frame(): Frame` | нічого; повертає проштампований кадр | [нижче](#власні-кадри) |

Методи сесії — `connect()`, `hello()`, `login()`, `logout()`, `disconnect()` — описано в
[Сесії](session.md).

## Що повертає команда

**Кожна команда повертає [`Response`](responses.md).** Не `null`, не масив, не bool. Об'єкт обгортає
розібрану відповідь, і кожен аксесор читає з нього.

```php
$response = $client->domain()->check(['example.com.ua']);

$response->code();        // int:  1000
$response->isSuccess();   // bool: true для будь-якого 1xxx
$response->message();     // string: серверний <msg>, мовою вашої сесії
$response->svTRID();      // string: ідентифікатор транзакції реєстру — зберігайте його
```

Три результати, під які треба писати код:

| Код | Значення | Що робити |
|---|---|---|
| `1000` | виконано | продовжуйте |
| `1001` | прийнято, завершується офлайн | **не надсилайте повторно.** Об'єкт несе статус `pending*`, а результат надходить пізніше як poll-сповіщення |
| `2xxx` | відмовлено; нічого не змінено | прочитайте код — за замовчуванням його вже спричинено як виняток |

Саме на `1001` і спотикаються. Це код успіху, тож `isSuccess()` дає `true` і нічого не спричиняється.
Перевіряйте його явно:

```php
$response = $client->domain()->create('example.com.ua', ['years' => 1, 'registrant' => 'C-0001']);

if ($response->isPending()) {
    // Збережіть svTRID() поруч із замовленням. Присуд надійде в чергу poll як
    // pendingActionData(), і саме svTRID із його paTRID зіставляє його з цією командою.
    $orders->markPending($response->svTRID());
}
```

Другу половину цього обміну див. у [Poll](poll.md).

Ще два коди є успіхом і не мають читатися як збій: **1300** (poll: черга порожня) та **1500**
(відповідь на `logout`).

## Ідентифікатори транзакцій клієнта
Кожна команда несе `clTRID`, який обираєте ви, а кожна відповідь несе `svTRID`, який призначає
реєстр.

| Ідентифікатор | Хто його задає | Для чого він |
|---|---|---|
| `clTRID` | цей клієнт | зіставити відповідь із запитом, що її спричинив |
| `svTRID` | реєстр | власний запис реєстру про цю операцію |

Бібліотека проштамповує унікальний `clTRID` на кожному кадрі, який будує. Форма така:

```
PHP-SDK-20260816103000-24191-0007
   │            │          │    └── лічильник, монотонний у межах цього екземпляра клієнта
   │            │          └──────── ідентифікатор процесу ОС, тож два робітники ніколи не зіткнуться
   │            └─────────────────── часова мітка UTC, YYYYMMDDHHMMSS: коли
   └──────────────────────────────── Config::$clTRIDPrefix
```

Задайте в `clTRIDPrefix` те, що впізнаваним чином називає вашу систему. Це людиночитна мітка для
зіставлення, а не секрет, і саме її вам зачитає служба підтримки реєстру.

**Зберігайте `svTRID` поруч з об'єктом, якого стосувалася команда.** Це єдине значення, за яким
підтримка може знайти операцію; `clTRID` не означає нічого ні для кого, крім вас. Записуйте обидва,
на кожній команді, зокрема на тих, що вдалися: саме з ними ви порівнюєте, коли пізніша не вдається.

### Перевірка відлуння
Сервер повертає ваш `clTRID` назад (RFC 5730 §2.5). Оскільки цей клієнт генерує унікальний
ідентифікатор на кожну команду, їх порівняння перетворює будь-яку розсинхронізацію потоку з тихого
хибного приписування на гучний збій: без нього відповідь, що належить попередній команді,
неможливо відрізнити від відповіді на цю, а для продовження чи реєстрації це означає записати як
виконаний не той домен.

Розбіжність спричиняє `ConnectionException` **і закриває з'єднання** — щойно зсуви розійшлися, кожен
наступний кадр у цьому потоці теж під сумнівом. Порівняння враховує сервер, який нормалізує
значення, адже схемний тип ідентифікатора транзакції — 3–64 символи: правомірно вкорочене чи
доповнене відлуння приймається, хибне — ні.

## Перемикач `throwOnFailure`

За замовчуванням будь-який код відповіді від 2000 і вище спричиняє `CommandException` (або той
підклас, що відповідає коду). Саме це робить прямолінійну інтеграцію правильною за замовчуванням: ви
не можете забути перевірити код, якого ніколи не бачите.

```php
$client->throwOnFailure(false);   // а (true) — щоб увімкнути назад
```

Коли він вимкнений, відмова повертається звичайним `Response`, і ви самі розгалужуєтесь за `code()`:

```php
use EppTools\ResultCode;

$client->throwOnFailure(false);

$response = $client->domain()->create('example.com.ua', ['years' => 1, 'registrant' => 'C-0001']);
if ($response->code() === ResultCode::OBJECT_EXISTS) {
    $taken[] = 'example.com.ua';
} elseif (!$response->isSuccess()) {
    throw new RuntimeException($response->message() ?? 'create failed');
}
```

Перемикач повертає клієнта, тож ланцюжиться, і діє на кожну наступну команду цього клієнта, доки ви
не повернете його назад.

Чого він **не** вимикає:

- `ConnectionException` — сервер узагалі не відповів, тож немає коду, який читати.
- `ValidationException` та `ConfigException` — нічого не було надіслано.
- `AuthenticationException` з `login()`. Невдалий вхід — це не та сесія, у якій можна продовжувати,
  тож він спричиняється незалежно від перемикача.
- Відмову, яку спричиняє `poll()->drain()`, коли відповідь на poll не є ні сповіщенням, ні порожньою
  чергою. З вимкненими винятками така відповідь доходить до циклу, замість того щоб бути спричиненою,
  і цикл спричиняє її явно, а не читає відмову як спорожнену чергу.

`EppTools\ResultCode` має іменовану константу для кожного коду — див. таблицю в
[Помилках](errors.md#коди-відповіді).

## Ключі параметрів перевіряються ще до побудови кадру

Команди, що приймають масив параметрів, відхиляють ключ, якого не розуміють, називаючи найближчий,
який розуміють:

```php
$client->domain()->create('example.com.ua', ['years' => 1, 'secdns' => [...]]);
// ValidationException: domain:create does not accept 'secdns' (did you mean 'secDNS'?).
// Accepted: authInfo, contacts, fee, license, nameServers, nameservers, registrant, secDNS, years.
```

Це свідомий компроміс. Інакше масив параметрів приймає будь-що: ключ, написаний із помилкою, у
хибному регістрі чи залишений від старішої інтеграції, просто ніколи не читається. Команда все одно
йде, реєстр усе одно відповідає 1000, а тієї частини, яку ви просили, немає: `'secdns'` замість
`'secDNS'` реєструє домен **непідписаним**, а помилка в написанні `'nameservers'` реєструє його
**без делегування**. Відповідь про це не каже нічого, бо з погляду реєстру ви й не просили.

Там, де два написання однаково виправдані, приймаються обидва: `nameservers` і `nameServers` для
domain create — це той самий параметр. Вкладені блоки теж перевіряються — блоки `add`, `rem`, `chg`
та `secDNS` в оновленні мають кожен свій перелік.

[Білдери](builders.md) прибирають цей клас помилок цілком: крок, написаний із помилкою, — це метод,
якого не існує, і ваш редактор скаже про це, поки ви його набираєте.

## Власні кадри
Усе, чого не охоплює високорівневий API, можна зібрати за допомогою `EppTools\Frame` і надіслати
через `Client::request()`.

```php
use EppTools\Namespaces;

$frame = $client->frame();                       // <command> із уже проставленим clTRID
$check = $frame->ns($frame->verb('check'), Namespaces::DOMAIN, 'domain:check');
$frame->ns($check, Namespaces::DOMAIN, 'domain:name', 'example.com.ua');

$response = $client->request($frame);
$response->availability();
```

`Client::frame()` — саме та точка входу, яка вам потрібна: вона повертає `Frame` зі згенерованим
`clTRID`, тож [перевірка відлуння](#перевірка-відлуння) далі захищає обмін. `Frame::command($clTRID)`
будує кадр із вашим власним ідентифікатором, і тоді за унікальність відповідаєте ви.

### API `Frame`

| Метод | Що робить |
|---|---|
| `Frame::command(string $clTRID): self` | Починає кадр `<command>` із цим ідентифікатором транзакції. |
| `verb(string $name): \DOMElement` | Додає дієслово команди — `check`, `info`, `create`, `update`, `renew`, `transfer`, `delete`, `poll`, `login`, `logout` — і повертає його, щоб було на чому нарощувати вміст. |
| `extension(): \DOMElement` | Елемент `<extension>`, створюваний за першого виклику і повертаний знову згодом, тож кілька розширень мають спільний блок. |
| `epp(\DOMElement $parent, string $name, ?string $text = null, array $attrs = []): \DOMElement` | Додає елемент у базовому просторі імен `epp-1.0`, без префікса. |
| `ns(\DOMElement $parent, string $ns, string $qname, ?string $text = null, array $attrs = []): \DOMElement` | Додає елемент із простору імен разом із його префіксом, напр. `domain:name`. |
| `document(): \DOMDocument` | Сам документ, для всього, чого два помічники додавання виразити не можуть. |
| `toXml(): string` | Серіалізує. `clTRID` записується останнім нащадком `<command>`, і саме такий порядок фіксує RFC 5730. |

Текст, переданий у `epp()` та `ns()`, додається як текстовий вузол, а не склеюється з рядком, тож `&`
і `<` у значенні екрануються за вас. `toXml()` безпечно викликати не один раз — залогуйте кадр,
потім надішліть — і результат несе рівно один `clTRID`, бо `<command>` із двома отримує голий 2001.

### `request(string|Frame $frame): Response`

Надсилає кадр і повертає розібрану відповідь. Приймає `Frame` або сирий XML:

```php
$response = $client->request($frame);
$response = $client->request('<?xml version="1.0" encoding="UTF-8"?><epp …>…</epp>');
```

Усе, що отримує звичайна команда, отримує і власний кадр: кадрування за довжиною, журналювання із
замаскованими секретами, перевірку відлуння `clTRID` і поведінку `throwOnFailure`.

### Розширення, якого бібліотека не моделює

Взірець того, як провезти розширення разом зі стандартною командою — тут вигаданий простір імен на
`domain:info`:

```php
use EppTools\Namespaces;

$frame = $client->frame();
$info  = $frame->ns($frame->verb('info'), Namespaces::DOMAIN, 'domain:info');
$frame->ns($info, Namespaces::DOMAIN, 'domain:name', 'example.com.ua', ['hosts' => 'all']);

$ext = $frame->extension();
$block = $frame->ns($ext, 'urn:example:params:xml:ns:thing-1.0', 'thing:info');
$frame->ns($block, 'urn:example:params:xml:ns:thing-1.0', 'thing:detail', 'full');

$response = $client->request($frame);
```

Дві речі, які треба зробити правильно. Оголосіть URI розширення під час входу — через
`Config::$extUris` або давши привітанню надати його — інакше в сервера немає підстав повертати дані
цього розширення. І читайте відповідь через `xpath()` або `values()`, бо іменовані аксесори знають
лише ті розширення, які моделює бібліотека; див. [Відповіді](responses.md#прямий-доступ).

### Константи просторів імен

`EppTools\Namespaces` містить точні рядки, які йдуть у канал передачі:

| Константа | URI | Визначено в |
|---|---|---|
| `EPP` | `urn:ietf:params:xml:ns:epp-1.0` | RFC 5730 |
| `DOMAIN` | `urn:ietf:params:xml:ns:domain-1.0` | RFC 5731 |
| `HOST` | `urn:ietf:params:xml:ns:host-1.0` | RFC 5732 |
| `CONTACT` | `urn:ietf:params:xml:ns:contact-1.0` | RFC 5733 |
| `SECDNS` | `urn:ietf:params:xml:ns:secDNS-1.1` | RFC 5910 (DNSSEC) |
| `RGP` | `urn:ietf:params:xml:ns:rgp-1.0` | RFC 3915 (відновлення) |
| `FEE` | `urn:ietf:params:xml:ns:epp:fee-1.0` | RFC 8748 (ціни) |
| `LOGINSEC` | `urn:ietf:params:xml:ns:epp:loginSec-1.0` | RFC 8807 (безпека входу) |
| `XSI` | `http://www.w3.org/2001/XMLSchema-instance` | XML Schema |

Ще дві є значеннями, а не просторами імен: `LOGINSEC_SENTINEL` — зарезервований рядок
`[LOGIN-SECURITY]`, який RFC 8807 кладе в `<pw>`, коли справжній пароль подорожує в розширенні, а
`DEFAULT_OBJ_URIS` / `DEFAULT_EXT_URIS` — переліки сервісів, які використовуються, лише якщо
привітання надійшло взагалі без меню сервісів.

### Власні розширення вашого реєстру

Кожен URI вище визначений якимось RFC і однаковий у будь-якого реєстру у світі. З ВЛАСНИМИ
розширеннями реєстру — ліцензією на торгову марку, ціною, балансом облікового запису — це вже не так,
і константи для них тут не буде: немає значення, яке було б правильним більш ніж для одного реєстру.

Вони **визначаються з `<greeting>`**. Будь-який сервер перелічує те, що підтримує, ще до того як ви
щось надішлете, тому одразу після `connect()` клієнт уже знає:

```php
$client->connect();

$client->registryExtUri();      // наприклад 'http://registry.example/epp/registry-1.0', або null
$client->registryBalanceUri();  // наприклад 'http://registry.example/epp/balance-1.0', або null
```

`null` означає, що цей сервер такого розширення не оголошує, — це факт про сервер, а не помилка.
Команди, яким розширення потрібне, про це кажуть, а не здогадуються: `domain:create` з `license`,
`host:delete` з `force` і `balance()` кидають `ConfigException`, називаючи, що саме знадобилося, і
перелічуючи, що сервер запропонував. У цій відмові й полягає суть. Розширення, надіслане у просторі імен,
якого сервер не знає, **ігнорується, а не відхиляється**, — тобто здогадка повернулася б як
`1000 OK` з мовчки невстановленою ліцензією.

Визначення зіставляє останній сегмент оголошеного URI — `.../registry-1.0`, `urn:…:balance`, — і це
домовленість, якої реєстри дотримуються, а не правило, дотримання якого хтось примушує. Якщо реєстр
називає свої розширення інакше, задайте їх самі — тоді привітання не питають:

```php
$config = Config::fromArray([
    'host' => 'epp.registry.example', 'clid' => 'EXAMPLE', 'password' => '...',
    'registryExtUri'     => 'urn:example:params:xml:ns:myreg-1.0',
    'registryBalanceUri' => 'urn:example:params:xml:ns:myreg-balance-1.0',
]);
```

## По одній команді за раз

Надішліть команду, прочитайте відповідь, тоді надсилайте наступну. Не конвеєризуйте команди в одному
з'єднанні, розраховуючи, що відповіді вишикуються по порядку. Якщо потрібна пропускна здатність,
відкривайте більше сесій, а не накладайте команди в одній — і пам'ятайте про обмеження одночасних
сесій, яке надходить як 2502.

---

[← Зміст посібника](README.md)
