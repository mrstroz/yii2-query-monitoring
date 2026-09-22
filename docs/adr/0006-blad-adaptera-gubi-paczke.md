# ADR-0006: Błąd adaptera gubi paczkę, bez ponowień i zapisu awaryjnego

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany |
| **Data** | 2026-09-22 |
| **Dotyczy** | Adaptery, [spec 03 §2](../spec/03-adaptery-wyjsciowe.md#2-błąd-adaptera) |

## Kontekst

Własny adapter może wysyłać paczkę do kolejki lub przez HTTP. Każda z tych operacji może rzucić wyjątek lub długo trwać. Adapter jest wywoływany w `EVENT_AFTER_REQUEST` lub w shutdown, czyli na ścieżce, na której żądanie już ma odpowiedź, ale proces FPM jest jeszcze zajęty.

## Decyzja

Wyjątek z `send()` jest przechwytywany, paczka przepada, biblioteka zapisuje jeden `Yii::error` na proces bez treści zapytań. Bez ponowień, bez zapisu awaryjnego do pliku. Za limit czasu odpowiada adapter.

## Konsekwencje

**Pozytywne:** jedna ścieżka błędu. Pakiet nie potrzebuje kolejki ponowień ani drugiego adaptera w konfiguracji.

**Negatywne:** awaria odbiorcy oznacza dziurę w danych bez możliwości odtworzenia. Wolny adapter wydłuża zajętość procesu FPM. `EVENT_AFTER_REQUEST` zachodzi przed `Response::send()`, więc wolny adapter opóźnia też odpowiedź dla klienta.

**Wymagania:** dokumentacja adaptera musi mówić o fazie shutdown i o limicie czasu.

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| Zapis awaryjny do pliku | Druga ścieżka do utrzymania i dane w dwóch miejscach do złożenia |
| Jedno ponowienie | Duplikaty przy timeoutach, podwójny czas przy awarii |
| Limit czasu wymuszany przez pakiet | PHP nie ma przenośnego sposobu przerwania wywołania synchronicznego |

## Kiedy wrócić do tej decyzji

Gdy powstanie worker i utrata paczek przy jego niedostępności stanie się kosztem, który ktoś zmierzył.
