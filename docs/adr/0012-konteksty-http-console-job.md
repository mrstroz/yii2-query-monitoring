# ADR-0012: Konteksty `http`, `console` i `job` z jawnymi granicami joba

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany. Zastępuje [ADR-0007](0007-zadanie-konsolowe-to-jeden-proces.md) |
| **Data** | 2026-09-28 |
| **Dotyczy** | Cykl życia paczek, [spec 01 §5](../spec/01-zbieranie-danych.md#5-konsola-i-joby), nagłówek [spec 02 §1](../spec/02-format-paczki.md#1-nagłówek) |

## Kontekst

[ADR-0007](0007-zadanie-konsolowe-to-jeden-proces.md) traktował worker kolejki jak jedno długie zadanie. Paczka workera nie mówiła, który job wykonał zapytanie, a reszta z krótkiego joba czekała na kolejną operację albo na koniec procesu. Diagnoza workera potrzebuje przypisania zapytań do jednej próby joba, a kod dziś i tak nie porcjuje konsoli: jeden kolektor na proces, limity obcinają jak w HTTP (`src/QueryMonitor.php`, `createCollector()`).

Recordery obu źródeł trzymają jeden obiekt kolektora przez cały proces: `sql\Source` wkłada go do `commandMap` (`src/sql/Source.php:65`), a `mongodb\Source` do subskrybenta na klucz klienta (`src/mongodb/Source.php:100`). Podmiana kolektora w komponencie nie zmieni celu wpisów.

`yii2-queue` 2.3.8 wyzwala `EVENT_BEFORE_EXEC`, `EVENT_AFTER_EXEC` i `EVENT_AFTER_ERROR` na jednym obiekcie `ExecEvent` w procesie, który wykonuje job. W trybie `isolate` jest to proces potomny `queue/exec`, nie listener. Inne kolejki mają własne zdarzenia albo żadnych.

## Decyzja

Wpis należy do **kontekstu**: `http` (żądanie), `console` (uruchomienie komendy) albo `job` (jedna próba joba). Każdy kontekst ma własne `id` i `seq` od 1. Kontekst korzenia powstaje w bootstrapie, joby otwiera aplikacja przez `QueryMonitor::beginJob()` i zamyka przez `endJob()` z uchwytem. Konteksty tworzą stos w jednym obiekcie procesu, a recordery obu źródeł pytają ten stos przy każdym wpisie. Wpis trafia do najgłębszego otwartego kontekstu. `http` wysyła jedną paczkę jak dotąd, `console` i `job` porcjują po limicie wpisów, rozmiaru i czasu. Metadane joba zapisuje nowe pole nagłówka `job`, a format dostaje `v: 3`.

## Konsekwencje

**Pozytywne:** zapytania workera są przypisane do próby joba, a krótki job wysyła resztę zaraz po zakończeniu, także gdy worker potem czeka. API nie zależy od żadnej kolejki. Dla `yii2-queue` pakiet daje opcjonalny behavior, reszta kolejek woła dwie metody w `try/finally`. `route` zachowuje znaczenie trasy kontrolera.

**Negatywne:** aplikacja musi oznaczyć granice joba. Bez tego zapytania joba należą do kontekstu komendy, jak w ADR-0007. Zmienia się format (`v: 3`) i sygnatury chronionych punktów rozszerzeń `createSources()` i `createCollector()`. Integracja, która nie zamyka jobów, trzyma do 16 otwartych kontekstów. Po wyczerpaniu tego limitu wpisy kolejnych jobów trafiają do najgłębszego istniejącego kontekstu, z jego metadanymi. Wykonania równoległe w jednym procesie (fibers, Swoole) nie są obsługiwane: stos zakłada zagnieżdżenie synchroniczne.

**Wymagania:** jeden stos kontekstów na proces, przekazywany recorderom zamiast kolektora. Blokada rekurencji na poziomie procesu, bo paczka joba może być wysyłana, gdy rodzic zbiera. Każdy kontekst ma własną flagę zakończenia, a proces ma flagę „sfinalizowano” z [ADR-0003](0003-finalizacja-w-after-request-i-shutdown.md). Dostępny limit głębokości stosu.

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| Worker jako jedno zadanie ([ADR-0007](0007-zadanie-konsolowe-to-jeden-proces.md)) | Bez przypisania zapytań do joba i z resztą krótkiego joba czekającą bez limitu czasu. ADR-0007 odrzucał ręczne API, bo wymaga zmian w kodzie aplikacji. Ten koszt to teraz dwie linie w `try/finally` albo jeden behavior, a bez niego zachowanie jest takie jak w ADR-0007 |
| Automatyczne rozpoznawanie jobów `yii2-queue` przez sam komponent | Zależność od jednej kolejki w rdzeniu. Behavior w pakiecie daje to samo jako opcja, bez zależności w `require` |
| Podmiana kolektora w komponencie przy każdym jobie | Recordery trzymają stary obiekt, więc wpisy trafiałyby do zamkniętej paczki |
| `id` wiadomości kolejki jako `id` paczki | Ponowienie joba mieszałoby dwie próby pod jednym `id`. Id wiadomości trafia do `job.message_id` |
| Metadane joba w `route` | `route` przestałoby znaczyć trasę kontrolera, a filtr po `route` mieszałby joby z akcjami |
| Timer przez `pcntl_alarm` dla bezczynnego workera | Nadal odrzucony z powodów z ADR-0007: `pcntl` nie wszędzie i konflikt z sygnałami workera. Koniec joba wysyła resztę, więc bezczynność po jobie niczego nie wstrzymuje |

## Kiedy wrócić do tej decyzji

Gdy aplikacje zaczną wykonywać joby równolegle w jednym procesie albo gdy limit 16 poziomów okaże się za mały dla prawdziwego zagnieżdżenia.
