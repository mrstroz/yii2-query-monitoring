# 02. Format paczki

Kontrakt między kolektorem a każdym adapterem. Zmiana pola oznacza nowe `v`.

## 1. Nagłówek

| Pole | Typ | Znaczenie |
|---|---|---|
| `v` | int | Wersja formatu, dziś `2`. Wersja `1` miała zamiast `route` pola `module`, `controller` i `action`, a wpis nie miał `caller` |
| `app` | string | Wartość `app` z konfiguracji |
| `type` | `http` \| `console` | Kontekst |
| `id` | string | Losowy identyfikator generowany przez bibliotekę, wspólny dla wszystkich paczek zadania: 16 znaków `[0-9a-f]` z `random_bytes(8)` |
| `seq` | int | Numer paczki w zadaniu konsolowym, od 1. Dla HTTP zawsze `1` |
| `route` | string \| null | `uniqueId` akcji wejściowej, np. `admin/orders/order/view` albo `site/index` |
| `ts` | string | Moment wysyłki, ISO 8601 w UTC |
| `host` | string | Wynik `gethostname()`, pusty tekst, gdy funkcja zwróci `false` |
| `dropped` | int | Wpisy pominięte po przekroczeniu limitu |
| `queries` | array | Lista wpisów w kolejności zakończenia |

`route` ma `null`, gdy żądanie skończyło się przed routingiem, np. błędem 404 w `UrlManager`.

## 2. Wpis

| Pole | Typ | Znaczenie |
|---|---|---|
| `db` | `mysql` \| `pgsql` \| `mongodb` | Z `driverName` połączenia SQL lub stałe dla MongoDB |
| `conn` | string | Id komponentu połączenia z listy w konfiguracji |
| `op` | string | Pierwsze słowo polecenia SQL małymi literami, po pominięciu białych znaków, komentarzy według dialektu z [§4](#4-normalizacja) i nawiasów otwierających (`WITH ...` daje `with`). Dla `db` spoza `mysql` i `pgsql` komentarzami są tylko `--` i `/* */`. Słowo to ciąg liter ASCII i `_`; gdy pierwszy jest inny znak albo niedomknięty komentarz, `op` jest pusty. Albo nazwa polecenia MongoDB z `getCommandName()` bez zmiany wielkości liter, np. `find`, `insert`, `getMore`, `findAndModify` ([01 §3](01-zbieranie-danych.md#3-źródło-mongodb)) |
| `query` | string \| null | Znormalizowany tekst, [§4](#4-normalizacja). `null`, gdy normalizacja jest niepewna |
| `time_ms` | float | Czas według [01 §2](01-zbieranie-danych.md#2-źródło-sql) i [01 §3](01-zbieranie-danych.md#3-źródło-mongodb) |
| `result` | `success` \| `error` | |
| `error` | string | Tylko przy `result: error`. SQLSTATE dla SQL, np. `"23000"`. Kod liczbowy sterownika jako tekst dla MongoDB, np. `"11000"`. Jeden typ dla obu źródeł |
| `caller` | array of string | Zawsze obecne. Najwyżej trzy ramki aplikacji, od najbliższej zapytaniu, każda jako `ścieżka:linia`, ze ścieżką względną wobec korzenia projektu. Szukane w pierwszych 64 ramkach śladu wywołań ([01 §2](01-zbieranie-danych.md#2-źródło-sql)). `[]` znaczy, że w tych granicach nie ma ramki aplikacji, a nie że zapytania nie wystawił kod aplikacji |

Jeden wpis to jedno polecenie faktycznie wysłane do bazy.

**Ramka aplikacji** to ramka śladu z plikiem pod korzeniem projektu, który nie leży w katalogu vendor Composera ani w `src/` pakietu i nie jest skryptem wejściowym. Korzeń projektu to katalog pakietu głównego Composera (`InstalledVersions::getRootPackage()`), katalog vendor to ten, z którego Composer załadował swój `ClassLoader`, a skrypt wejściowy to pierwszy plik wykonany przez PHP, z pominięciem pliku `auto_prepend_file` (`web/index.php`, `yii`). Ramki spoza korzenia projektu są pomijane. Ramka kodu wykonanego przez `eval()`, czyli taka, której plik zawiera `: eval()'d code`, nie jest ramką aplikacji: miejscem jest samo wywołanie `eval()`, które ślad podaje jako osobną ramkę i które podlega tym samym regułom. Argumenty funkcji nie są odczytywane.

## 3. Przykład

```json
{"v":2,"app":"shop-api","type":"http","id":"req_9f3a1c2e","seq":1,
 "route":"admin/orders/order/view",
 "ts":"2026-09-22T09:41:05.312Z","host":"web-03","dropped":0,
 "queries":[
  {"db":"mysql","conn":"db","op":"select","query":"SELECT * FROM `order` WHERE `id` = ?","time_ms":2.1,"result":"success","caller":["modules/admin/modules/orders/controllers/OrderController.php:41"]},
  {"db":"mysql","conn":"db","op":"insert","query":"INSERT INTO `audit_log` (`order_id`, `action`) VALUES (?, ?)","time_ms":0.9,"result":"error","error":"23000","caller":["models/AuditLog.php:27","modules/admin/modules/orders/controllers/OrderController.php:44"]},
  {"db":"mongodb","conn":"mongodb","op":"find","query":"contacts filter{externalId:?,tenantId:?} sort{updatedAt:?} limit:?","time_ms":1.3,"result":"success","caller":["components/ContactRepository.php:88","modules/admin/modules/orders/controllers/OrderController.php:52"]},
  {"db":"mysql","conn":"db","op":"select","query":null,"time_ms":0.7,"result":"success","caller":[]}
 ]}
```

## 4. Normalizacja

Założenie: nazwy pól, tabel, kolekcji i parametrów są kodem aplikacji, nie danymi użytkowników. Klucz w stylu `{"users.alice@example.com": true}` zostanie ujawniony. Za takie klucze odpowiada aplikacja.

**SQL, reguły wspólne.**

| Element | Reguła |
|---|---|
| Symbole parametrów `:name`, `?` | Zostają bez zmian, poza parametrem Yii z następnego wiersza |
| Parametr Yii: `:qp` i same cyfry (`QueryBuilder::PARAM_PREFIX`) | Zamiana na `?`. `:qpx`, `:qp1a` i `::qp0` zostają |
| Literały tekstowe `'...'` | Zamiana na `?`, także wewnątrz zapytania z parametrami |
| Literały liczbowe, w tym w `LIMIT` i `OFFSET`, ułamki i wykładnik (`1.5`, `1e5`) | Zamiana na `?` |
| Minus przed liczbą | Zostaje jako operator: `-2` daje `-?` |
| Cyfry w identyfikatorze lub symbolu parametru (`table1`, `:id2`, `$1`) | Zostają |
| Cyfry, po których bez odstępu stoją litery (`123abc`, `1table`) | `query: null` |
| Komentarze `--`, `/* */` | Zamiana na jedną spację |
| Niedomknięty komentarz `/* ...` | `query: null` |
| Białe znaki poza literałami | Ciąg zamieniany na jedną spację, bez spacji na początku i końcu |
| Nazwy tabel i kolumn | Zostają |
| Lista w nawiasie tuż po słowie `IN`, także `NOT IN`, o co najmniej dwóch elementach, z których każdy jest wartością albo krotką wartości. Wartość to `?`, `:name`, `$1`, `NULL`, `TRUE`, `FALSE` (bez względu na wielkość liter), `DATE ?`, `TIME ?` i `TIMESTAMP ?`, każda także ze znakiem `+` lub `-` przed nią | Pierwszy element i `...`: `IN (?, ?, ?)` daje `IN (?, ...)`, `IN ((?, ?), (?, ?))` daje `IN ((?, ?), ...)`. Lista z jednym elementem, pusta, z podzapytaniem, wyrażeniem, rzutowaniem (`?::int`) albo funkcją zostaje. Niedomknięta lista zostaje bez zmian. Zwijanie przed obcięciem do `maxQueryLength` ([ADR 0011](../adr/0011-zwijanie-list-in-i-parametrow-yii.md)) |
| Niedomknięty literał, nieznana konstrukcja | `query: null` |
| `db` inne niż `mysql` i `pgsql` | `query: null` |
| Tekst, który nie jest poprawnym UTF-8 | `query: null` |
| Długość | Obcięcie do `maxQueryLength` bajtów UTF-8 razem z `…` na końcu, na granicy znaku, dopiero po normalizacji |

**SQL, reguły dialektu.** Składnia MySQL i PostgreSQL różni się w cudzysłowie, więc normalizator wybiera reguły po `db`. Dla MySQL wspierany jest tylko domyślny `sql_mode` MySQL 8: `"..."` to literał, backslash w literale to znak ucieczki. `ANSI_QUOTES` i `NO_BACKSLASH_ESCAPES` są poza zakresem i nie są wykrywane. Aplikacja z takim trybem dostanie błędnie znormalizowany `query` z nazwami zamienionymi na `?`, ale bez wycieku wartości.

| Element | MySQL | PostgreSQL |
|---|---|---|
| `"..."` | Literał tekstowy, zamiana na `?` | Cytowany identyfikator, zostaje |
| Ucieczka w `'...'` | `\'` i `''` | Tylko `''`. Backslash jest zwykłym znakiem (`standard_conforming_strings` domyślnie włączone) |
| `0x1F`, `X'1F'`, `b'01'` | Literał, zamiana na `?` | `X'1F'`, `B'01'` jako literał, zamiana na `?` |
| `` `...` `` | Identyfikator, zostaje | Nie występuje |
| Komentarz `#` | Zamiana na jedną spację | Nie jest komentarzem |
| `--` | Komentarz tylko, gdy po nim stoi biały znak lub koniec tekstu. `1--1` to dwa minusy | Komentarz |
| `$1`, `$2` | Nie występuje | Symbol parametru, zostaje |
| Rzutowanie `::int` | Nie występuje | Zostaje |
| Zagnieżdżony komentarz `/* /* */ */` | Nie występuje | `query: null`, `op` pusty |
| `E'...'`, `$$...$$`, `$tag$...$tag$` | Nie występuje | Literał, zamiana na `?`. W `E'...'` backslash jest znakiem ucieczki. Niedomknięty daje `null`. Inny `$` niż `$cyfry` i otwarcie literału daje `null` |

**MongoDB.** Postać tekstowa to nazwa kolekcji, a po niej sekcje polecenia w kolejności `filter`, `update`, `pipeline`, `sort`, `limit`, `skip`. Każda sekcja ma postać `nazwa{...}`, `nazwa[...]` albo `nazwa:?`. Klucze w sekcji stoją w kolejności z polecenia, bez spacji, np. `contacts filter{externalId:?,tenantId:?} sort{updatedAt:?} limit:?`. Normalizator czyta dokument polecenia z `CommandStartedEvent::getCommand()` i bierze z niego tylko pola z tabeli poleceń. Pola `lsid`, `$clusterTime`, `txnNumber`, `$db`, `$readPreference`, `batchSize`, `projection` i każde inne nigdy nie trafiają do `query`.

| Polecenie | Kolekcja | Sekcje |
|---|---|---|
| `find` | wartość `find` | `filter` z `filter`, `sort`, `limit`, `skip` |
| `count` | wartość `count` | `filter` z `query`, `limit`, `skip` |
| `findAndModify` | wartość `findAndModify` | `filter` z `query`, `update` z `update`, `sort` |
| `update` | wartość `update` | `filter` z `updates[0].q`, `update` z `updates[0].u`. Więcej niż jedna instrukcja w `updates` daje `null` |
| `delete` | wartość `delete` | `filter` z `deletes[0].q`. Więcej niż jedna instrukcja w `deletes` daje `null` |
| `aggregate` | wartość `aggregate` | `pipeline` z `pipeline`, np. `orders pipeline[{$match:{status:?}},{$group:{_id:?,n:{$sum:?}}}]` |
| `insert` | wartość `insert` | Tylko liczba dokumentów w `documents`, np. `contacts n:3` |
| `getMore` | wartość `collection` | Brak, np. `contacts` |
| Każde inne, np. `createIndexes`, `killCursors`, `distinct` | | `query: null` |

| Element | Reguła |
|---|---|
| Nazwy pól, operatory `$set`, `$in`, `$match`, etapy potoku | Zostają |
| Każda wartość, także liczba w `sort` i `limit`, wartość logiczna i `null` | Zamiana na `?` |
| Obiekt BSON (`ObjectId`, `UTCDateTime`, `Regex`, `Binary`, `Decimal128` i inne) | Jedna wartość, zamiana na `?`, bez zaglądania do środka |
| Tablica | `[...]` z elementami po przecinku, każdy według tych reguł. Tablice nie są scalane: `$in:[?,?]` i `$in:[?,?,?]` to różne `query` |
| Brak sekcji w poleceniu | Sekcja pominięta. Pusty filtr daje `filter{}` |
| Zagnieżdżenie | Poziomy liczy się po kluczach: klucze sekcji i klucze etapu `pipeline` to poziom pierwszy, klucze dokumentu pod kluczem to kolejny, tablica nie jest poziomem. Klucz ponad pięć poziomów daje `query: null`. `filter{$and:[{$and:[{tenantId:?},{status:{$in:[?,?]}}]},{k:?}]}`, czyli trzy `andWhere()` w Yii, ma cztery poziomy, a `filter{a:{b:{c:{d:{e:{f:?}}}}}}` sześć |
| Pusta kolekcja albo nazwa pola, nazwa ze znakiem `{`, `}`, `[`, `]`, `,`, `:`, białym (także Unicode, np. NBSP), sterującym albo nie w UTF-8 | `query: null` |
| Kolekcja, która nie jest tekstem (np. `aggregate: 1` na bazie) | `query: null` |
| Długość ponad `maxQueryLength` | `query: null`, bez obcinania |

Wartości parametrów, dokumenty, adresy URL z parametrami, dane uwierzytelniające i ścieżki bezwzględne nigdy nie trafiają do paczki.

## 5. Limity

| Limit | HTTP | Konsola |
|---|---|---|
| `maxEntries` | Kolektor przestaje dodawać wpisy i zwiększa `dropped`. Błędy po limicie też przepadają | Paczka jest wysyłana natychmiast po dodaniu wpisu numer `maxEntries` |
| `maxBatchBytes`, liczony dla całego JSON z nagłówkiem | Jak wyżej, ten sam licznik | Kolektor wysyła dotychczasowy bufor, bieżący wpis trafia do następnej paczki |
| Pojedynczy wpis z nagłówkiem ponad `maxBatchBytes` | Wpis przepada, `dropped` rośnie | Wpis przepada, `dropped` bieżącej paczki rośnie |
| `maxQueryLength` | [§4](#4-normalizacja) | [§4](#4-normalizacja) |

Niezmiennik: JSON paczki nigdy nie przekracza `maxBatchBytes`. Kolektor liczy bajty nagłówka z bieżącymi wartościami, z miejscem na `seq` i `dropped` do 10 cyfr, oraz bajty każdego wpisu po serializacji. Gdy akcja wejściowa ustawiona po wpisach wydłuży nagłówek ponad limit, przy zamknięciu kolektor usuwa wpisy od końca i dolicza je do `dropped`. Wpis dodany po zamknięciu kolektora jest pomijany i nie zwiększa `dropped`. W HTTP pierwszy wpis, który nie mieści się w `maxEntries` lub `maxBatchBytes`, kończy przyjmowanie: każdy następny, także krótszy, tylko zwiększa `dropped`, więc lista jest pełna do pierwszego osiągniętego limitu ([00 §6](00-przeglad-i-zakres.md#6-kryteria-sukcesu)).

Wpis z `query` obciętym do `maxQueryLength` mieści się w `maxBatchBytes` przy wartościach początkowych, także z `caller`: trzy ścieżki, każda najwyżej 4096 bajtów (`PATH_MAX` w Linuksie), to razem około 12 KB. Ostatni wiersz tabeli dotyczy więc tylko konfiguracji z bardzo małym limitem paczki.

## 6. Poza zakresem

Sumy, grupy, histogramy i percentyle. Odbiorca liczy je z listy. Pełny ślad wywołań, argumenty funkcji i treść odpowiedzi z bazy nie są częścią wpisu; miejsce w kodzie jest w nim tylko jako `caller` ([ADR 0009](../adr/0009-caller-i-route-w-formacie-v2.md)).
