# ADR-0004: Normalizacja literałów na `?`, przy niepewności `null`

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany |
| **Data** | 2026-09-22 |
| **Dotyczy** | Normalizacja, [spec 02 §4](../spec/02-format-paczki.md#4-normalizacja) |

## Kontekst

Active Record w Yii 2 wiąże wartości `WHERE` jako parametry `:qp0`, ale `LIMIT` i `OFFSET` z `yii\db\QueryBuilder::buildLimit()` wpisuje jako liczby wprost w tekst. Zapytania pisane ręcznie przez `createCommand()` mogą mieć literały tekstowe. Kryterium sukcesu 2 wymaga, żeby paczka nie zawierała wartości i żeby ta sama struktura dawała ten sam `query`.

Kolejny etap potoku MongoDB, `$match` z dokumentem, ma wartości w zagnieżdżonej strukturze, nie w tekście. Atlas Search zagnieżdża głębiej niż filtr Yii: w rzeczywistej aplikacji (2026-09-25) potok wyszukiwania ma `$searchMeta.facet.operator.compound.filter`, a w nim kolejny compound albo `embeddedDocument`, czyli klucze na siódmym i dalszym poziomie.

## Decyzja

SQL: symbole parametrów zostają (poza `:qpN` z [ADR 0011](0011-zwijanie-list-in-i-parametrow-yii.md)), każdy literał tekstowy i liczbowy jest zamieniany na `?`, komentarze są usuwane. Reguły cudzysłowu i komentarzy zależą od dialektu, bo `"..."` jest literałem w MySQL i identyfikatorem w PostgreSQL. Gdy skaner napotka niedomknięty literał lub nieznaną konstrukcję, `query` ma `null`. MongoDB: nazwy pól, operatory i etapy zostają na każdej głębokości, każda wartość jest zamieniana na `?`, przekroczenie długości daje `null`.

## Konsekwencje

**Pozytywne:** paginacja daje ten sam `query`. Niepewność nie wycieka, tylko gubi tekst. Potok Atlas Search o dowolnym zagnieżdżeniu ma tekst.

**Negatywne:** skaner literałów to własny kod z przypadkami brzegowymi na dialekt. MySQL z `ANSI_QUOTES` lub `NO_BACKSLASH_ESCAPES` dostaje błędny `query`, bo tryb nie jest wykrywany. Nazwy pól MongoDB zbudowane z danych użytkownika są ujawniane.

**Wymagania:** testy normalizacji dla MySQL i PostgreSQL. Założenie o kluczach zapisane w spec. Normalizator MongoDB schodzi rekurencyjnie bez limitu głębokości. Normalizator czyta polecenie w zdarzeniu startu, zanim serwer je sprawdzi, więc limit zagnieżdżenia serwera go nie ogranicza. Wywołania funkcji PHP nie zużywają stosu C, więc głębokość kosztuje pamięć proporcjonalną do dokumentu, który aplikacja już zbudowała.

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| Tylko parametry, literały dają `null` | Każde zapytanie z paginacją miałoby `null` |
| Pełna normalizacja ze scalaniem `IN` i białych znaków | Więcej kodu w parserze przy niejasnym zysku. Scalanie list rozstrzyga [ADR 0011](0011-zwijanie-list-in-i-parametrow-yii.md) bez zmiany formatu |
| Zamiana wartości MongoDB na typ (`int`, `str`, `oid`) | Więcej informacji, ale bez odbiorcy, który by z niej korzystał |
| Klucz MongoDB głębiej niż pięć poziomów daje `null` | Każde wywołanie Atlas Search z compound w compound miało `null`. Głębokość nie chroni przed wyciekiem, bo wartość na każdym poziomie jest `?` |

## Kiedy wrócić do tej decyzji

Gdy `query: null` pojawia się w więcej niż kilku procentach wpisów.
