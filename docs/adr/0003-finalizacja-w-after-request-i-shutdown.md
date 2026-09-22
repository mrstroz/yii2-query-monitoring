# ADR-0003: Finalizacja w EVENT_AFTER_REQUEST z awaryjnym shutdown

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany |
| **Data** | 2026-09-22 |
| **Dotyczy** | Cykl życia kolektora, [spec 01 §4](../spec/01-zbieranie-danych.md#4-żądanie-http) |

## Kontekst

`yii\base\Application::run()` wyzwala `EVENT_AFTER_REQUEST` po `handleRequest()`. Nieobsłużony wyjątek trafia do `yii\base\ErrorHandler::handleException()`, który renderuje odpowiedź i kończy proces przez `exit(1)`, więc `EVENT_AFTER_REQUEST` nie następuje. Bez dodatkowego mechanizmu paczka z zapytaniem, które rzuciło wyjątek, przepada.

## Decyzja

Finalizacja następuje w `EVENT_AFTER_REQUEST`. Przy starcie komponent rejestruje `register_shutdown_function`, która wykonuje finalizację, jeśli jeszcze nie nastąpiła. Operacje po finalizacji nie są zliczane.

## Konsekwencje

**Pozytywne:** `exit()` i `exit(1)` z `ErrorHandler` nie gubią paczki. Jedna paczka na żądanie, `seq` w HTTP zawsze `1`.

**Negatywne:** błąd krytyczny PHP nadal gubi paczkę. Adapter może działać w fazie shutdown, gdy część komponentów Yii jest zamknięta. Zapytania po `EVENT_AFTER_REQUEST` są niewidoczne.

**Wymagania:** flaga „sfinalizowano” w kolektorze, ustawiana przed budową paczki, także przy pustym buforze, i nie cofana przy błędzie adaptera. Sprawdzana w obu ścieżkach.

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| Akceptacja utraty | Gubi dokładnie te paczki, które zawierają błędy |
| Podpięcie pod `ErrorHandler` | Wymaga podmiany klasy `errorHandler` w konfiguracji aplikacji. Druga rzecz do skonfigurowania i kolizja z własnymi handlerami |
| Druga paczka po finalizacji | Wprowadza `seq` do HTTP dla rzadkiego przypadku |

## Kiedy wrócić do tej decyzji

Gdy aplikacje zaczną wykonywać zapytania w handlerach po `EVENT_AFTER_REQUEST` i te zapytania będą przedmiotem diagnozy.
