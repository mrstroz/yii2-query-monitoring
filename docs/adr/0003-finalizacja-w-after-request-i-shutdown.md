# ADR-0003: Finalizacja w EVENT_AFTER_REQUEST z awaryjnym shutdown

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany |
| **Data** | 2026-09-22 |
| **Dotyczy** | Cykl życia procesu, [spec 01 §4](../spec/01-zbieranie-danych.md#4-żądanie-http) i [§5](../spec/01-zbieranie-danych.md#5-konsola-i-joby) |

## Kontekst

`yii\base\Application::run()` wyzwala `EVENT_AFTER_REQUEST` po `handleRequest()`. Nieobsłużony wyjątek trafia do `yii\base\ErrorHandler::handleException()`, który renderuje odpowiedź i kończy proces przez `exit(1)`, więc `EVENT_AFTER_REQUEST` nie następuje. Bez dodatkowego mechanizmu paczka z zapytaniem, które rzuciło wyjątek, przepada.

## Decyzja

Finalizacja procesu następuje w `EVENT_AFTER_REQUEST`. Przy starcie komponent rejestruje `register_shutdown_function`, która wykonuje finalizację, jeśli jeszcze nie nastąpiła. Finalizacja kończy wszystkie otwarte konteksty ([ADR-0012](0012-konteksty-http-console-job.md)) od najgłębszego do korzenia, a każdy wysyła swoją resztę. Operacje po finalizacji nie są zliczane.

## Konsekwencje

**Pozytywne:** `exit()` i `exit(1)` z `ErrorHandler` nie gubią paczki, także reszty komendy i joba, którego aplikacja nie zamknęła. Jedna paczka na kontekst HTTP, `seq` w HTTP zawsze `1`.

**Negatywne:** błąd krytyczny PHP nadal gubi paczkę. Adapter może działać w fazie shutdown, gdy część komponentów Yii jest zamknięta. Zapytania po `EVENT_AFTER_REQUEST` są niewidoczne.

**Wymagania:** flaga „sfinalizowano” na jedynej ścieżce finalizacji procesu, a nie w kolektorze, bo błąd przy budowie paczki zostawiłby kolektor otwarty. Ustawiana przed budową pierwszej paczki, także przy pustym buforze, i nie cofana przy błędzie adaptera ani kolektora. Sprawdzana w obu ścieżkach. Zakończenie kontekstu, wysłanie paczki i finalizacja procesu to trzy osobne operacje: kontekst ma własną flagę zakończenia, także nie cofaną, więc zakończony job nie wysyła drugi raz w shutdown.

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| Akceptacja utraty | Gubi dokładnie te paczki, które zawierają błędy |
| Podpięcie pod `ErrorHandler` | Wymaga podmiany klasy `errorHandler` w konfiguracji aplikacji. Druga rzecz do skonfigurowania i kolizja z własnymi handlerami |
| Druga paczka po finalizacji | Wprowadza `seq` do HTTP dla rzadkiego przypadku |

## Kiedy wrócić do tej decyzji

Gdy aplikacje zaczną wykonywać zapytania w handlerach po `EVENT_AFTER_REQUEST` i te zapytania będą przedmiotem diagnozy.
