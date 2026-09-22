# ADR-0001: Podmiana klasy Command zamiast profilera Yii

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany |
| **Data** | 2026-09-22 |
| **Dotyczy** | Źródło SQL, [spec 01 §2](../spec/01-zbieranie-danych.md#2-źródło-sql) |

## Kontekst

Yii 2 ma wbudowany profiler: `enableProfiling` w `yii\db\Connection` opakowuje każde polecenie w `Yii::beginProfile()` i `Yii::endProfile()`, a `enableLogging` zapisuje tekst SQL z wartościami do logu. Oba mechanizmy przechodzą przez `yii\log\Logger`, który trzyma wiadomości w pamięci do końca żądania i wymaga celu logowania. Na produkcji są wyłączane z powodu kosztu i wycieku wartości parametrów.

`yii\db\Connection::createCommand()` tworzy obiekt klasy z `commandClass` albo, gdy ten ma wartość domyślną, z `commandMap` dla drivera. `commandMap` przyjmuje tablicę konfiguracji, więc obiekt dostaje zależności bez statyków. `commandClass` jest przestarzały od Yii 2.0.14. Active Record, `Query` i migracje używają tej metody, więc podmiana klasy obejmuje je bez zmian w modelach.

## Decyzja

Pakiet dostarcza klasę rozszerzającą `yii\db\Command` i ustawia ją przez `commandMap` dla drivera każdego monitorowanego połączenia. Pomiar obejmuje `PDO::prepare()` i `PDOStatement::execute()`. Pakiet nie zależy od `enableProfiling` ani `enableLogging` i nie zmienia tych ustawień.

## Konsekwencje

**Pozytywne:** brak zależności od `Logger`. Pomiar tylko tego, co wysłano do bazy. Wartości parametrów nigdy nie opuszczają `Command`.

**Negatywne:** `begin`, `commit` i `rollback` idą przez PDO w `yii\db\Transaction` i nie są mierzone. Własna klasa `Command` w aplikacji koliduje z podmianą. Błąd przy odczycie kursora po `execute()` jest niewidoczny. Klasa pakietu powtarza kod `prepare()` i `internalExecute()` z Yii 2.0.55 i czyta prywatne `_isolationLevel` i `_retryHandler`, więc każda aktualizacja Yii wymaga porównania tych dwóch metod.

**Wymagania:** aplikacja nie może ustawiać własnego `commandClass` ani `commandMap` dla drivera monitorowanego połączenia.

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| `enableProfiling` plus własny cel logowania | Logger buforuje wiadomości i zapisuje SQL z wartościami. Koszt i wyciek |
| Opakowanie obiektu PDO | Widzi transakcje, ale wymaga własnego `pdoClass` i traci kontekst Yii (nazwa połączenia, cache) |
| Zdarzenia `Connection` | Yii nie ma zdarzeń per polecenie, tylko `EVENT_AFTER_OPEN` i transakcyjne |

## Kiedy wrócić do tej decyzji

Gdy czas `commit` stanie się problemem diagnostycznym. Wtedy dochodzą zdarzenia transakcyjne `Connection`, bez zmiany tej decyzji.
