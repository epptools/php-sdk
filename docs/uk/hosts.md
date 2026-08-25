# Хости

Об'єкти хостів (серверів імен) відповідають **RFC 5732**. Там, де реєстр делегує домени за
посиланням, сервер імен має існувати як об'єкт хоста, перш ніж [домен](domains.md) зможе вказати на
нього через `<domain:hostObj>`. Там, де реєстр натомість приймає вбудовані glue-адреси, вам,
можливо, не доведеться створювати об'єкт хоста взагалі — див.
[дві моделі серверів імен](domains.md#сервери-імен-дві-моделі).

Кожна команда для хостів доступна через `$client->host()` і повертає [`Response`](responses.md).
Усе тут припускає підключений клієнт із виконаним входом — див. [Сесія](session.md).

## Методи

| Метод | Команда EPP |
|---|---|
| `check(array $names): Response` | `<check>` |
| `info(string $name): Response` | `<info>` |
| `create(string $name, array $addresses = []): Response` | `<create>` |
| `update(string $name, array $options = []): Response` | `<update>` |
| `updateBuilder(string $name): HostUpdateBuilder` | будує `<update>` |
| `delete(string $name, bool $force = false): Response` | `<delete>`, за потреби з розширенням примусового відкріплення |

Для хоста немає ні `renew`, ні `transfer`: RFC 5732 не визначає жодного з них. Хост іде слідом за
доменом, під яким живе, і виставляти рахунок за нього нема за що.

## Підпорядковані та зовнішні хости

Ця відмінність вирішує, чи може хост узагалі нести адреси, і саме вона є джерелом більшості відмов
під час першого запуску:

| | живе під | glue-адреси |
|---|---|---|
| **підпорядкований** | доменом у зоні, яку обслуговує цей реєстр (`ns1.example.com.ua` під `example.com.ua`) | **обов'язкові** — без жодної create дає `2003` |
| **зовнішній** | доменом деінде (`ns1.acme.example`) | **відхиляються** — його адреси живуть у його власному реєстрі, тож надіслати адресу означає `2306` |

Клієнт, який завжди надсилає адресу, має пропускати її для зовнішніх хостів. Адреси мають бути
публічними інтернет-адресами, і діє обмеження на один хост (13 у цьому реєстрі), понад яке кадр
відхиляється.

---

## check

```php
public function check(array $names): Response
```

**У каналі передачі:** `<command><check><host:check><host:name>…` — RFC 5732 §3.1.1.

```php
$r = $client->host()->check(['ns1.example.com.ua', 'ns2.example.com.ua']);

$r->availability();                       // ['ns1.example.com.ua' => false, 'ns2.example.com.ua' => true]
$r->isAvailable('ns2.example.com.ua');    // true | false | null
$r->unavailableReason('ns1.example.com.ua');
```

`avail => false` означає, що об'єкт хоста в реєстрі вже існує, — а це часто саме те, що вам
потрібно, бо на хост, який ви збиралися створити, можна просто послатися. Об'єкти хостів — це
простір імен на весь реєстр: сервер імен, створений іншим реєстратором, видно й вам, і посилаєтеся
ви на нього за іменем.

**Коди відповіді:** `1000` на будь-який коректно сформований check; `2005` називає синтаксично
недійсне ім'я хоста.

---

## info

```php
public function info(string $name): Response
```

**У каналі передачі:** `<command><info><host:info><host:name>` — RFC 5732 §3.1.2. Аргумента
`authInfo` тут немає: об'єкт хоста не має власного коду трансферу.

```php
$h = $client->host()->info('ns1.example.com.ua');

$h->objectName();       // 'ns1.example.com.ua'
$h->roid();             // власний ідентифікатор об'єкта в реєстрі
$h->statuses();         // ['ok'], ['linked'], ['clientUpdateProhibited'], …
$h->sponsor();          // clID
$h->createdBy();        // crID          $h->createdDate();  // crDate
$h->updatedBy();        // upID, або null $h->updatedDate();  // upDate

foreach ($h->hostAddresses() as $addr) {
    echo $addr['version'], ' ', $addr['ip'], "\n";   // 'v4 203.0.113.10'
}
```

`hostAddresses()` повертає `[['ip' => '203.0.113.10', 'version' => 'v4'], …]`. **Порожній список —
це нормальна відповідь для зовнішнього хоста**, а не відсутня: glue-адреси несе лише хост усередині
зони, яку обслуговує реєстр.

Статус `linked` означає, що принаймні один домен використовує цей хост як сервер імен. Саме він
стоїть між вами і [видаленням](#delete).

**Коди відповіді:** `1000`; `2303` (такого хоста немає).

---

## create

```php
public function create(string $name, array $addresses = []): Response
```

**У каналі передачі:** `<command><create><host:create>` з одним `<host:addr ip="v4|v6">` на кожну
адресу — RFC 5732 §3.2.1.

Версія IP визначається із самого літерала, тож ви передаєте плаский список, а `v4` і `v6` отримують
правильні позначки:

```php
// Підпорядкований хост: glue-адреси обов'язкові.
$r = $client->host()->create('ns1.example.com.ua', ['203.0.113.10', '2001:db8::10']);

echo $r->objectName(), ' created ', $r->createdDate(), "\n";

// Зовнішній хост: жодних адрес.
$client->host()->create('ns1.acme.example');
```

Повне перше делегування — створіть хости, а потім наведіть на них домен:

```php
foreach (['ns1.example.com.ua' => '203.0.113.10', 'ns2.example.com.ua' => '203.0.113.11'] as $ns => $ip) {
    if ($client->host()->check([$ns])->isAvailable($ns) === true) {
        $client->host()->create($ns, [$ip]);
    }
}

$client->domain()->update('example.com.ua', [
    'add' => ['ns' => ['ns1.example.com.ua', 'ns2.example.com.ua']],
]);

echo implode(', ', $client->domain()->info('example.com.ua')->nameservers()), "\n";
```

**Коди відповіді:** `1000`; `2001` (адрес більше, ніж дозволяє обмеження на хост); `2003`
(підпорядкований хост без адреси); `2005` (некоректна адреса або ім'я); `2302` (хост уже існує);
`2306` (адреса на зовнішньому хості).

---

## update

```php
public function update(string $name, array $options = []): Response
```

**У каналі передачі:** `<command><update><host:update>` — RFC 5732 §3.2.5. Як і кожне оновлення в
EPP, це **дельта**: те, чого ви не згадали, ніхто не чіпає.

| ключ | значення | канал передачі |
|---|---|---|
| `addAddresses` | `string[]` | `<host:addr>` усередині `<host:add>` |
| `remAddresses` | `string[]` | `<host:addr>` усередині `<host:rem>` |
| `addStatuses` | `string[]` | `<host:status s="…">` усередині `<host:add>` |
| `remStatuses` | `string[]` | `<host:status s="…">` усередині `<host:rem>` |

```php
// Перенумерувати сервер імен: додайте нову адресу і приберіть стару однією командою, щоб
// хост ніколи не лишався без glue-адреси.
$client->host()->update('ns1.example.com.ua', [
    'addAddresses' => ['203.0.113.20'],
    'remAddresses' => ['203.0.113.10'],
]);

echo implode(', ', array_column($client->host()->info('ns1.example.com.ua')->hostAddresses(), 'ip')), "\n";
```

Адреса, яку ви видаляєте, має збігатися з тим, що тримає реєстр. Блок того боку, який ви не
використовуєте, не надсилається взагалі, тож `['addAddresses' => ['203.0.113.20']]` дає `<host:add>`
і більше нічого.

Статуси, які ви можете встановлювати, — це родина `client*`: `clientUpdateProhibited` та
`clientDeleteProhibited`. `linked`, `ok` і статуси `server*` належать реєстру.

Тут діють ті самі правила щодо адрес, що й у create: зовнішній хост не може отримати адреси
(`2306`), а підпорядкований не може лишитися без жодної (`2003`).

**Коди відповіді:** `1000`; `2001` (адрес більше, ніж дозволяє обмеження на хост); `2003`; `2303`;
`2304` (статус забороняє); `2306`.

### Побудова update крок за кроком

```php
public function updateBuilder(string $name): HostUpdateBuilder
```

`addAddress` / `addAddresses`, `remAddress` / `remAddresses`, `addStatus`, `remStatus`, потім
`send()`. Кожен крок описано в [Білдери](builders.md).

---

## Перейменування не існує
**Об'єкт хоста неможливо перейменувати.** Реєстр читає лише блоки `add` та `rem` у `host:update`;
зміні імені нема куди подітися, і кадр, який ніс би її поряд зі зміною адрес, застосував би адреси,
відкинув перейменування — і все одно відповів би `1000`, лишивши вас у переконанні, що сервер імен
переїхав, хоча він не переїхав.

Тому `update()` відхиляє опцію `newName` прямо:

```php
$client->host()->update('ns1.example.com.ua', ['newName' => 'ns9.example.com.ua']);
// ValidationException: host:update cannot rename a nameserver at this registry — create the
// new host, repoint the domains that use it, then delete the old one
```

Ця послідовність і є перейменуванням, і в ній три кроки:

```php
// 1. Створіть новий хост із тими самими адресами.
$old = $client->host()->info('ns1.example.com.ua');
$client->host()->create('ns9.example.com.ua', array_column($old->hostAddresses(), 'ip'));

// 2. Переспрямуйте кожен домен, який використовує старий. Списку таких доменів на боці
//    реєстру немає — він береться з ваших власних записів про те, що куди ви делегували.
foreach ($yourDomainsUsingIt as $domain) {
    $client->domain()->update($domain, [
        'add' => ['ns' => ['ns9.example.com.ua']],
        'rem' => ['ns' => ['ns1.example.com.ua']],
    ]);
}

// 3. Лише коли на нього вже ніщо не посилається — інакше це буде 2305.
$client->host()->delete('ns1.example.com.ua');
```

Додавайте перед тим, як видаляти, однією командою на домен, щоб домен ніколи навіть на мить не
лишався без делегування.

---

## delete

```php
public function delete(string $name, bool $force = false): Response
```

**У каналі передачі:** `<command><delete><host:delete>` — RFC 5732 §3.2.2. Із `$force` у
`<extension>` їде блок `<registry:delete><registry:deleteNS confirm="yes"/>`.

Хост, який усе ще є сервером імен для якогось домену, видалити неможливо: реєстр відповідає
**`2305`**. Статус `linked` — це попередження наперед.

```php
$h = $client->host()->info('ns1.example.com.ua');

if (in_array('linked', $h->statuses(), true)) {
    // Спершу відкріпіть його від доменів, або скористайтеся примусовим видаленням нижче.
    return;
}

$client->host()->delete('ns1.example.com.ua');
```

### Примусове видалення

```php
$client->host()->delete('ns1.example.com.ua', force: true);
```

Це прибирає хост із набору серверів імен **кожного** домену, який на нього посилався, а потім
видаляє його. Обов'язковий для реєстру `confirm="yes"` надсилається за вас — і саме тому цей
прапорець є окремим аргументом, а не типовим значенням.

Перш ніж ним користуватися, зрозумійте його ціну: домен, у якого лишилося менше серверів імен, ніж
вимагає зона, стає `inactive` і перестає резолвитися. Це правильний інструмент для сервера імен,
який ви виводите з експлуатації, і неправильний — для наведення ладу. Де можете, спершу
переспрямуйте домени й скористайтеся звичайним видаленням.

**Коди відповіді:** `1000`; `2303` (такого хоста немає); `2305` (усе ще використовується як сервер
імен — звичайне видалення); `2400` (примусове відкріплення не вдалося завершити).

---

## Коди відповіді на цій сторінці

| Код | Значення | Виняток |
|---|---|---|
| `1000` | виконано | — |
| `2001` | кадр некоректно сформований — напр. адрес більше, ніж дозволяє обмеження на хост | `CommandException` |
| `2003` | підпорядкований хост без glue-адреси | `CommandException` |
| `2005` | некоректна адреса або ім'я хоста | `CommandException` |
| `2302` | хост уже існує | `ObjectExistsException` |
| `2303` | такого хоста немає | `ObjectDoesNotExistException` |
| `2304` / `2305` | статус забороняє / усе ще використовується як сервер імен | `ObjectStatusException` |
| `2306` | політика — напр. адреса на зовнішньому хості | `PolicyException` |
| `2400` | реєстр не зміг це завершити; може бути тимчасовим | `CommandException` (`isRetryable()`) |

Опція `newName` ніколи не доходить до реєстру: це `ValidationException`, кинутий ще до того, як буде
побудовано кадр. `ResultCode` має іменовану константу для кожного коду вище; повна таксономія — у
[Помилки](errors.md).

---

Див. також: [Домени](domains.md) · [Контакти](contacts.md) · [Poll](poll.md) ·
[Відповіді](responses.md) · [Білдери](builders.md)

[← Зміст посібника](README.md)
