# ADR-0004: Normalizacja literałów na `?`, przy niepewności `null`

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany |
| **Data** | 2026-09-22 |
| **Dotyczy** | Normalizacja, [spec 02 §4](../spec/02-format-paczki.md#4-normalizacja) |

## Kontekst

Active Record w Yii 2 wiąże wartości `WHERE` jako parametry `:qp0`, ale `LIMIT` i `OFFSET` z `yii\db\QueryBuilder::buildLimit()` wpisuje jako liczby wprost w tekst. Zapytania pisane ręcznie przez `createCommand()` mogą mieć literały tekstowe. Kryterium sukcesu 2 wymaga, żeby paczka nie zawierała wartości i żeby ta sama struktura dawała ten sam `query`.

Kolejny etap potoku MongoDB, `$match` z dokumentem, ma wartości w zagnieżdżonej strukturze, nie w tekście.

## Decyzja

SQL: symbole parametrów zostają, każdy literał tekstowy i liczbowy jest zamieniany na `?`, komentarze są usuwane. Reguły cudzysłowu i komentarzy zależą od dialektu, bo `"..."` jest literałem w MySQL i identyfikatorem w PostgreSQL. Gdy skaner napotka niedomknięty literał lub nieznaną konstrukcję, `query` ma `null`. MongoDB: nazwy pól, operatory i etapy zostają, każda wartość jest zamieniana na `?`, zagnieżdżenie ponad trzy poziomy lub przekroczenie długości daje `null`.

## Konsekwencje

**Pozytywne:** paginacja daje ten sam `query`. Niepewność nie wycieka, tylko gubi tekst.

**Negatywne:** `IN (?, ?, ?)` i `IN (?, ?)` to różne `query`. Skaner literałów to własny kod z przypadkami brzegowymi na dialekt. MySQL z `ANSI_QUOTES` lub `NO_BACKSLASH_ESCAPES` dostaje błędny `query`, bo tryb nie jest wykrywany. Nazwy pól MongoDB zbudowane z danych użytkownika są ujawniane.

**Wymagania:** testy normalizacji dla MySQL i PostgreSQL. Założenie o kluczach zapisane w spec.

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| Tylko parametry, literały dają `null` | Każde zapytanie z paginacją miałoby `null` |
| Pełna normalizacja ze scalaniem `IN` i białych znaków | Więcej kodu w parserze przy niejasnym zysku. Można dodać później bez zmiany formatu |
| Zamiana wartości MongoDB na typ (`int`, `str`, `oid`) | Więcej informacji, ale bez odbiorcy, który by z niej korzystał |

## Kiedy wrócić do tej decyzji

Gdy `query: null` pojawia się w więcej niż kilku procentach wpisów albo gdy odbiorca potrzebuje scalania `IN`.
