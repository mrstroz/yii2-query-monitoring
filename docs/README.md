# Dokumentacja: yii2-query-monitoring

Pakiet Composer dla Yii 2, który dla każdego żądania HTTP i zadania konsolowego zbiera płaską listę zapytań do MySQL, PostgreSQL i MongoDB i przekazuje ją adapterowi.

## Jak korzystać

Specyfikacja (`spec/`) opisuje, **co** system robi. ADR-y (`adr/`) zapisują, **dlaczego** wybrano dane rozwiązanie i co odrzucono. Plan (`plan/`) mówi, **kiedy i co teraz**.

Zaczynasz pracę? [`plan/roadmap.md`](plan/roadmap.md) to jedyne miejsce, w które trzeba zajrzeć pierwsze.

Zmieniasz zachowanie? Najpierw poprawiasz specyfikację, potem kod, i edytujesz to, co jest, zamiast notować, że się zmieniło. Zmieniasz decyzję architektoniczną? Aktualizujesz ADR, który ją opisuje. Nowy ADR jest dla nowej decyzji albo zastąpionej w całości. Starych ADR-ów nie usuwamy.

## Specyfikacja

| Dokument | Zawartość |
|---|---|
| [`spec/00-przeglad-i-zakres.md`](spec/00-przeglad-i-zakres.md) | Cel, główna zasada, zakres, poza zakresem, użytkownicy, kryteria sukcesu, środowiska, słownik, otwarte kwestie |
| [`spec/01-zbieranie-danych.md`](spec/01-zbieranie-danych.md) | Komponent i konfiguracja, źródło SQL, źródło MongoDB, cykl życia HTTP i konsoli, ochrona aplikacji |
| [`spec/02-format-paczki.md`](spec/02-format-paczki.md) | Nagłówek, wpis, przykład, normalizacja, limity |
| [`spec/03-adaptery-wyjsciowe.md`](spec/03-adaptery-wyjsciowe.md) | Kontrakt adaptera, błąd adaptera, adapter plikowy, test wydajności |

## Plan prac

| Dokument | Zawartość |
|---|---|
| [`plan/README.md`](plan/README.md) | Format zadania, identyfikatory, definicja ukończenia, jak zlecać pracę |
| [`plan/roadmap.md`](plan/roadmap.md) | Stan na dziś, etapy, kolejność, ryzyka |

## Decyzje architektoniczne

| ADR | Decyzja | Status |
|---|---|---|
| [0001](adr/0001-podmiana-klasy-command-zamiast-profilera.md) | Podmiana klasy `Command` zamiast profilera Yii | Zaakceptowany |
| [0002](adr/0002-plaska-lista-zamiast-agregatow.md) | Płaska lista zapytań zamiast agregatów | Zaakceptowany |
| [0003](adr/0003-finalizacja-w-after-request-i-shutdown.md) | Finalizacja w `EVENT_AFTER_REQUEST` z awaryjnym shutdown | Zaakceptowany |
| [0004](adr/0004-normalizacja-literalow-na-znak-zapytania.md) | Normalizacja literałów na `?`, przy niepewności `null` | Zaakceptowany |
| [0005](adr/0005-adapter-plikowy-z-blokada-i-utrata-paczki.md) | Adapter plikowy JSON Lines z blokadą nieblokującą | Zaakceptowany |
| [0006](adr/0006-blad-adaptera-gubi-paczke.md) | Błąd adaptera gubi paczkę, bez ponowień | Zaakceptowany |
| [0007](adr/0007-zadanie-konsolowe-to-jeden-proces.md) | Zadanie konsolowe to jedno uruchomienie procesu | Zaakceptowany |

Szablon nowego ADR: [`adr/template.md`](adr/template.md).

## Inne pliki

| Plik | Co zawiera |
|---|---|
| [`query-monitoring-library-brief.md`](query-monitoring-library-brief.md) | Brief, z którego powstała specyfikacja. Treść przeniesiona do `spec/`. Przy rozbieżności obowiązuje `spec/` |

## Konwencje

- Dokumentacja po polsku. Pliki specyfikacji numerowane `NN-nazwa.md`, ADR-y `NNNN-nazwa.md`, etapy `E0`, `E1`.
- Odwołania do kodu to ścieżki względem katalogu głównego repozytorium, np. `src/Command.php:41`.
- Daty w formacie `RRRR-MM-DD`, zapisywane jak `2026-09-22`. Bez wyrażeń względnych.
- Cudzysłów polski `„ ”`. Identyfikatory, nazwy pól i ścieżki zostają w oryginale.
- `CLAUDE.md` po angielsku.
