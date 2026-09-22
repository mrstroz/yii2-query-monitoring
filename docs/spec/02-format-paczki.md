# 02. Format paczki

Kontrakt między kolektorem a każdym adapterem. Zmiana pola oznacza nowe `v`.

## 1. Nagłówek

| Pole | Typ | Znaczenie |
|---|---|---|
| `v` | int | Wersja formatu. Pierwsza wersja to `1` |
| `app` | string | Wartość `app` z konfiguracji |
| `type` | `http` \| `console` | Kontekst |
| `id` | string | Losowy identyfikator generowany przez bibliotekę, wspólny dla wszystkich paczek zadania |
| `seq` | int | Numer paczki w zadaniu konsolowym, od 1. Dla HTTP zawsze `1` |
| `module` | string \| null | `uniqueId` modułu akcji wejściowej. `null` dla głównej aplikacji |
| `controller` | string \| null | Lokalne id kontrolera akcji wejściowej |
| `action` | string \| null | Lokalne id akcji wejściowej |
| `ts` | string | Moment wysyłki, ISO 8601 w UTC |
| `host` | string | Wynik `gethostname()` |
| `dropped` | int | Wpisy pominięte po przekroczeniu limitu |
| `queries` | array | Lista wpisów w kolejności zakończenia |

`module`, `controller` i `action` mają `null`, gdy żądanie skończyło się przed routingiem, np. błędem 404 w `UrlManager`.

## 2. Wpis

| Pole | Typ | Znaczenie |
|---|---|---|
| `db` | `mysql` \| `pgsql` \| `mongodb` | Z `driverName` połączenia SQL lub stałe dla MongoDB |
| `conn` | string | Id komponentu połączenia z listy w konfiguracji |
| `op` | string | Pierwsze słowo polecenia SQL małymi literami, albo nazwa polecenia MongoDB: `find`, `insert`, `update`, `delete`, `aggregate`, `getMore`, `count` |
| `query` | string \| null | Znormalizowany tekst, [§4](#4-normalizacja). `null`, gdy normalizacja jest niepewna |
| `time_ms` | float | Czas według [01 §2](01-zbieranie-danych.md#2-źródło-sql) i [01 §3](01-zbieranie-danych.md#3-źródło-mongodb) |
| `result` | `success` \| `error` | |
| `error` | string | Tylko przy `result: error`. SQLSTATE dla SQL, np. `"23000"`. Kod liczbowy sterownika jako tekst dla MongoDB, np. `"11000"`. Jeden typ dla obu źródeł |

Jeden wpis to jedno polecenie faktycznie wysłane do bazy.

## 3. Przykład

```json
{"v":1,"app":"shop-api","type":"http","id":"req_9f3a1c2e","seq":1,
 "module":"admin/orders","controller":"order","action":"view",
 "ts":"2026-09-22T09:41:05.312Z","host":"web-03","dropped":0,
 "queries":[
  {"db":"mysql","conn":"db","op":"select","query":"SELECT * FROM `order` WHERE `id` = :qp0","time_ms":2.1,"result":"success"},
  {"db":"mysql","conn":"db","op":"insert","query":"INSERT INTO `audit_log` (`order_id`, `action`) VALUES (:qp0, :qp1)","time_ms":0.9,"result":"error","error":"23000"},
  {"db":"mongodb","conn":"mongodb","op":"find","query":"contacts filter{externalId:?,tenantId:?} sort{updatedAt:?} limit:?","time_ms":1.3,"result":"success"},
  {"db":"mysql","conn":"db","op":"select","query":null,"time_ms":0.7,"result":"success"}
 ]}
```

## 4. Normalizacja

Założenie: nazwy pól, tabel, kolekcji i parametrów są kodem aplikacji, nie danymi użytkowników. Klucz w stylu `{"users.alice@example.com": true}` zostanie ujawniony. Za takie klucze odpowiada aplikacja.

**SQL, reguły wspólne.**

| Element | Reguła |
|---|---|
| Symbole parametrów `:name`, `?` | Zostają bez zmian |
| Literały tekstowe `'...'` | Zamiana na `?`, także wewnątrz zapytania z parametrami |
| Literały liczbowe, w tym w `LIMIT` i `OFFSET` | Zamiana na `?` |
| Komentarze `--`, `/* */` | Usunięte |
| Nazwy tabel i kolumn | Zostają |
| `IN (?, ?, ?)` | Nie jest scalane. Różna długość listy daje różny `query` |
| Niedomknięty literał, nieznana konstrukcja | `query: null` |
| Długość | Obcięcie do `maxQueryLength` z `…` na końcu, dopiero po normalizacji |

**SQL, reguły dialektu.** Składnia MySQL i PostgreSQL różni się w cudzysłowie, więc normalizator wybiera reguły po `db`. Dla MySQL wspierany jest tylko domyślny `sql_mode` MySQL 8: `"..."` to literał, backslash w literale to znak ucieczki. `ANSI_QUOTES` i `NO_BACKSLASH_ESCAPES` są poza zakresem i nie są wykrywane. Aplikacja z takim trybem dostanie błędnie znormalizowany `query` z nazwami zamienionymi na `?`, ale bez wycieku wartości.

| Element | MySQL | PostgreSQL |
|---|---|---|
| `"..."` | Literał tekstowy, zamiana na `?` | Cytowany identyfikator, zostaje |
| `` `...` `` | Identyfikator, zostaje | Nie występuje |
| Komentarz `#` | Usunięty | Nie jest komentarzem |
| `$1`, `$2` | Nie występuje | Symbol parametru, zostaje |
| Rzutowanie `::int` | Nie występuje | Zostaje |
| `E'...'`, `$$...$$` | Nie występuje | Literał, zamiana na `?`. Niedomknięty daje `null` |

**MongoDB.** Postać tekstowa: kolekcja, potem sekcje polecenia w kolejności `filter`, `update`, `pipeline`, `sort`, `limit`, `skip`, każda jako `nazwa{...}` lub `nazwa:?`. Klucze w sekcji w kolejności z polecenia, bez spacji, np. `contacts filter{externalId:?,tenantId:?} sort{updatedAt:?} limit:?`.

| Element | Reguła |
|---|---|
| Nazwy pól, operatory `$set`, `$match`, etapy potoku | Zostają |
| Każda wartość, także liczba w `sort` i `limit` | Zamiana na `?` |
| `insert` | Tylko kolekcja i liczba dokumentów, np. `contacts n:3` |
| Zagnieżdżenie ponad trzy poziomy | `query: null` |
| Długość ponad `maxQueryLength` | `query: null`, bez obcinania |

Wartości parametrów, dokumenty, adresy URL z parametrami i dane uwierzytelniające nigdy nie trafiają do paczki.

## 5. Limity

| Limit | HTTP | Konsola |
|---|---|---|
| `maxEntries` | Kolektor przestaje dodawać wpisy i zwiększa `dropped`. Błędy po limicie też przepadają | Paczka jest wysyłana natychmiast po dodaniu wpisu numer `maxEntries` |
| `maxBatchBytes`, liczony dla całego JSON z nagłówkiem | Jak wyżej, ten sam licznik | Kolektor wysyła dotychczasowy bufor, bieżący wpis trafia do następnej paczki |
| Pojedynczy wpis z nagłówkiem ponad `maxBatchBytes` | Wpis przepada, `dropped` rośnie | Wpis przepada, `dropped` bieżącej paczki rośnie |
| `maxQueryLength` | [§4](#4-normalizacja) | [§4](#4-normalizacja) |

Niezmiennik: JSON paczki nigdy nie przekracza `maxBatchBytes`. Kolektor liczy bajty nagłówka z bieżącymi wartościami, z miejscem na `seq` i `dropped` do 10 cyfr, oraz bajty każdego wpisu po serializacji. Gdy akcja wejściowa ustawiona po wpisach wydłuży nagłówek ponad limit, przy zamknięciu kolektor usuwa wpisy od końca i dolicza je do `dropped`. Wpis dodany po zamknięciu kolektora jest pomijany i nie zwiększa `dropped`. W HTTP pierwszy wpis, który nie mieści się w `maxEntries` lub `maxBatchBytes`, kończy przyjmowanie: każdy następny, także krótszy, tylko zwiększa `dropped`, więc lista jest pełna do pierwszego osiągniętego limitu ([00 §6](00-przeglad-i-zakres.md#6-kryteria-sukcesu)).

Wpis z `query` obciętym do `maxQueryLength` mieści się w `maxBatchBytes` przy wartościach początkowych, więc ostatni wiersz tabeli dotyczy tylko konfiguracji z bardzo małym limitem paczki.

## 6. Poza zakresem

Sumy, grupy, histogramy i percentyle. Odbiorca liczy je z listy. Miejsce w kodzie, ślad wywołań i treść odpowiedzi z bazy nie są częścią wpisu.
