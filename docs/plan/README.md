# Plan prac

`spec/` mówi, **co** budujemy, `adr/` mówi, **dlaczego**, a ten katalog mówi, **kiedy i co teraz**.

Plan nie opisuje zachowania systemu. Każde zadanie wskazuje sekcję specyfikacji, w której to zachowanie jest zapisane. Korekta specyfikacji nie wymaga więc edycji tutaj, a przesunięcie zadania nie dotyka specyfikacji.

## Pliki

| Plik | Zawartość |
|---|---|
| [`roadmap.md`](roadmap.md) | Stan na dziś, etapy, kolejność, ryzyka |
| [`01-fundament-i-sql.md`](01-fundament-i-sql.md) | E0: pakiet, kolektor, źródło SQL, cykl życia HTTP, aplikacja testowa |
| [`02-adapter-plikowy.md`](02-adapter-plikowy.md) | E1: adapter plikowy z rotacją |
| [`03-testy.md`](03-testy.md) | E2: architektura i konwencje testów |
| [`04-kontekst-wpisu.md`](04-kontekst-wpisu.md) | E3: kontekst wpisu (`caller`, `route`), format `v: 2`, limity |
| [`05-mongodb.md`](05-mongodb.md) | E4: źródło MongoDB |
| [`05a-zwijanie-list.md`](05a-zwijanie-list.md) | E4a: zwijanie list `IN` i parametrów `:qpN` w `query` |
| [`05b-pelniejszy-query-mongodb.md`](05b-pelniejszy-query-mongodb.md) | E4b: `query` MongoDB dla głębokich dokumentów, `distinct` i długich poleceń |
| [`06-konsola.md`](06-konsola.md) | E5: zadania konsolowe |
| [`07-wydajnosc-i-odbior.md`](07-wydajnosc-i-odbior.md) | E6: test wydajności, dokumentacja użytkownika |

## Format zadania

```markdown
- [ ] (^) **YQM-12** <co powstaje>
      Spec: [02 §1](../spec/02-format-paczki.md#1-nagłówek) · Zależy od: YQM-11
      Gotowe, gdy: <jeden warunek, sprawdzalny>

- [-] (v) **YQM-14** ~~<czego postanowiliśmy nie budować>~~
      **Odrzucone RRRR-MM-DD.** <dlaczego, w jednym lub dwóch zdaniach>
```

- **Checkbox** niesie stan: `[ ]` otwarte, `[x]` zrobione, `[-]` odrzucone.
- **Priorytet** stoi między checkboxem a numerem. `(^)`: reszta etapu na to czeka. `(=)`: domyślny, wymagane, ale nic na tym nie wisi. `(v)`: etap zamyka się bez tego. Brak tokenu czyta się jako `(=)`. `(^)` mówi, co idzie *pierwsze*, nie co jest *konieczne*. Najwyżej jedna trzecia otwartych zadań etapu może go mieć.
- **Tytuł** mówi, co powstaje, nie jak.
- **Spec** i **ADR**: linki względne z numerem sekcji w tekście. Etap porządkujący testy nie zmienia zachowania produktu, więc jego zadania linkują [ADR 0008](../adr/0008-architektura-i-konwencje-testow.md) i [`tests/README.md`](../../tests/README.md) zamiast specyfikacji. Gdy nagłówek się zmieni i kotwica przestanie działać, `02 §1` nadal prowadzi w dobre miejsce. Najwyżej dwa dokumenty na zadanie.
- **Zależy od**: tylko zadania z tego planu.
- **Blokada**: coś spoza planu, co musi wydarzyć się pierwsze. Projekt nie ma zależności w innych repozytoriach, więc blokady rozstrzyga tabela otwartych kwestii w [`spec/00 §9`](../spec/00-przeglad-i-zakres.md#9-otwarte-kwestie). Zablokowane zadanie czeka, aż wpis tam zostanie rozstrzygnięty.
- **Gotowe, gdy**: tylko tam, gdzie warunek nie wynika z tytułu. Jeden warunek, sprawdzalny testem albo na działającym systemie.
- **Jedna linia kontekstu** pod otwartym zadaniem: dlaczego istnieje albo co pokazał pomiar. Nie jak system działa, od tego jest link do spec.
- **Odrzucone**: `[-]`, tytuł przekreślony i datowana notatka `**Odrzucone RRRR-MM-DD.**` z powodem. Numer zostaje.

## Identyfikatory i commity

Numeracja `YQM-NN` biegnie ciągle przez cały projekt, niezależnie od pliku i etapu. Numery nie są używane ponownie: porzucone zadanie staje się `[-]` z datowanym powodem i zostaje na liście, przeniesione zadanie zachowuje numer.

Jedno zadanie to jeden commit z identyfikatorem w treści. Commity po angielsku, np. `feat: YQM-5 measured Command class`.

## Definicja ukończenia

Wspólna dla każdego zadania, więc nie jest powtarzana:

1. `composer test` przechodzi (PHPUnit). Zadanie YQM-1 tworzy ten skrypt.
2. `composer stan` przechodzi bez błędów (PHPStan na poziomie ustalonym w YQM-1).
3. `composer cs` nie zgłasza różnic (PHP CS Fixer).
4. Jeśli zadanie zmieniło zachowanie względem specyfikacji: **najpierw poprawiona specyfikacja, potem kod.**
5. Checkbox odhacza ten, kto skończył zadanie, w tym samym commicie.

## Jak zlecać pracę

„Zrób YQM-12” wystarczy. Kolejność: przeczytać zadanie i podlinkowane sekcje specyfikacji, zaimplementować, odhaczyć checkbox i podmienić „Stan na dziś” w [`roadmap.md`](roadmap.md), commit.

Gdy zadanie okazuje się zależeć od czegoś, czego nie ma w planie, dodajemy nowe zadanie zamiast rozdymać bieżące.
