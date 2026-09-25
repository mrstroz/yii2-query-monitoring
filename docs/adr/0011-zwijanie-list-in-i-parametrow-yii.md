# ADR-0011: Zwijanie list `IN` i parametrów `:qpN` Yii

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany |
| **Data** | 2026-09-25 |
| **Dotyczy** | Normalizacja, [spec 02 §4](../spec/02-format-paczki.md#4-normalizacja) |

## Kontekst

[ADR 0004](0004-normalizacja-literalow-na-znak-zapytania.md) zostawił symbole parametrów bez zmian i nie scalał list. Na rzeczywistej aplikacji `yii\db\QueryBuilder::buildInCondition()` daje jeden parametr na wartość, więc `where(['material_num' => $ids])` z 211 wartościami zapisuje `IN (:qp0, …, :qp210)`, ponad 1,5 KB tekstu. Ta sama struktura z inną liczbą wartości daje inny `query`, a numery `:qpN` stojące za listą zależą od jej długości: `IN (…) AND b = :qp3` i `IN (…) AND b = :qp211` to różne `query`. Odbiorca nie pogrupuje takich wpisów po tekście.

Yii nazywa parametry `QueryBuilder::PARAM_PREFIX` (`:qp`) i kolejnym numerem. Numer mówi o pozycji w zapytaniu, nie o strukturze. Klucz złożony daje krotki: `(a, b) IN ((:qp0, :qp1), (:qp2, :qp3))`. `yii2-mongodb` daje z tego samego warunku `$in` z tablicą wartości.

## Decyzja

SQL: parametr `:qp` z samymi cyframi staje się `?`, a lista w nawiasie po słowie `IN` z co najmniej dwoma elementami, z których każdy jest wartością albo krotką wartości, staje się pierwszym elementem i `...`: `IN (?, ...)`. Wartością są też `NULL`, `TRUE`, `FALSE`, literał daty i czasu oraz wartość ze znakiem. Inne nazwy parametrów zostają. MongoDB: tablica pod `$in` lub `$nin` z co najmniej dwiema wartościami staje się `[?,...]`, także jako drugi argument wyrażenia agregacji `$in`. Napis od `$` to ścieżka pola lub zmienna, nie wartość, więc wyłącza zwijanie.

## Konsekwencje

**Pozytywne:** ta sama struktura daje ten sam `query` niezależnie od liczby wartości w liście. Długa lista nie zjada `maxQueryLength` ani `maxBatchBytes`.

**Negatywne:** `query` nie mówi, ile wartości miała lista, ani czy miała jedną (`IN (?)`) czy więcej. Ręczny parametr nazwany `:qp5` też staje się `?`. Wpisy zapisane przed zmianą mają inny `query` niż te po niej, przy tym samym `v`.

**Wymagania:** skaner śledzi nawiasy, bo lista w identyfikatorze `` `…` `` lub `"…"` nie może być zwinięta. Testy zwijania dla MySQL, PostgreSQL i MongoDB.

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| Lista jako `IN (?)` | Nie odróżnia listy od jednej wartości |
| Lista jako `IN (...)` | Traci postać elementu, np. krotkę `(?, ?)` |
| Każdy parametr, także `:id` i `$1`, jako `?` | Nazwy parametrów pisanych ręcznie pomagają znaleźć zapytanie w kodzie, a ich numeracja nie zależy od długości listy |
| Nowe `v` formatu | Pola się nie zmieniają, zmienia się tylko tekst `query`. ADR 0004 zapowiedział to bez zmiany formatu |
| Zwinięcie wielowierszowego `VALUES` z `batchInsert()` | Poza zakresem tej zmiany. Wracamy, gdy `INSERT` z wieloma wierszami zacznie przekraczać `maxQueryLength` |

## Kiedy wrócić do tej decyzji

Gdy odbiorca potrzebuje długości listy, np. do wykrywania zapytań z tysiącami wartości w `IN`.
