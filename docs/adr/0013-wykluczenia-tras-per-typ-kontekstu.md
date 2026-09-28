# ADR-0013: Wykluczenia tras osobno dla każdego typu kontekstu

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany |
| **Data** | 2026-09-28 |
| **Dotyczy** | Konfiguracja `excludedRoutes`, [spec 01 §5.4](../spec/01-zbieranie-danych.md#54-wykluczenia-tras) |

## Kontekst

Listener kolejki (`queue/listen`, `queue/run`, w trybie `isolate` także `queue/exec`) co kilka sekund odpytuje tabelę kolejki. Te zapytania zalewają paczki komendy, choć nikt ich nie diagnozuje. To samo dotyczy tras w rodzaju `health/index` w HTTP. Joby wykonywane w listenerze nadal mają być mierzone ([ADR-0012](0012-konteksty-http-console-job.md)).

Trasa jest znana dopiero w `EVENT_BEFORE_ACTION`. Wcześniej, w bootstrapie, aplikacja może już wykonać zapytania. Kontekst konsoli wysyła paczkę po limicie, więc mógłby wysłać ją przed ustaleniem, że trasa jest wykluczona.

## Decyzja

`excludedRoutes` ma osobne listy dla `http`, `console` i `job`. Wzorzec pasuje dokładnie do `route` (dla `job` do nazwy joba przed obcięciem) albo, gdy kończy się `*`, jako prefiks. Samo `*` pasuje do wszystkiego. Wzorzec pusty, zaczynający się od `/` albo z `*` w środku to błędna konfiguracja, bo nigdy nie pasuje w zamierzony sposób. Wykluczenie dotyczy tylko wpisów tego kontekstu. Każdy kontekst potomny ocenia się własnym kluczem. Kontekst korzenia nie wysyła niczego, dopóki trasa nie jest znana, a przy wykluczeniu odrzuca bufor bez zliczania.

## Konsekwencje

**Pozytywne:** listener może być wykluczony, a joby w nim nadal mierzone. Reguła jest prosta do sprawdzenia i nie wymaga wyrażeń regularnych. Wykluczone zapytania nie zniekształcają `dropped`.

**Negatywne:** przed poznaniem trasy kontekst korzenia trzyma bufor i przy limicie tylko zlicza nadwyżkę, jak HTTP. Bootstrap z ponad 500 zapytaniami traci więc wpisy także w konsoli. Wpisy wykluczonego joba przepadają: nie trafiają do rodzica.

**Wymagania:** stan kontekstu „trasa nieznana”, sprawdzanie wykluczenia przy ustawieniu trasy i przy `beginJob()`. Błędny wzorzec lub klucz to błędna konfiguracja pakietu.

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| Jedna lista tras dla wszystkich typów | `queue/run` w konsoli i ta sama nazwa w innym typie to różne rzeczy. Nie da się wykluczyć listenera bez wykluczenia czegoś innego |
| Wyrażenia regularne albo glob z `*` w środku | Język filtrów bez potrzeby. Przypadki z listenerem i health checkiem pokrywa dokładne dopasowanie i prefiks |
| Wykluczenie dziedziczone przez joby | Wykluczony listener nie mierzyłby jobów, a tego właśnie potrzeba |
| Wysyłanie bufora przed poznaniem trasy | Wykluczona komenda wysłałaby zapytania z bootstrapu |

## Kiedy wrócić do tej decyzji

Gdy wykluczenia będą potrzebne po czymś innym niż trasa albo nazwa joba, np. po połączeniu.
