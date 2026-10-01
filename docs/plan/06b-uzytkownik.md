# E5b. Identyfikator użytkownika w paczce

**Cel:** odbiorca widzi, dla którego użytkownika aplikacji paczka zebrała zapytania, bez dodatkowych zapytań do bazy przy ustawieniach domyślnych.

**Koniec etapu:** z `user: true` paczka HTTP ma identyfikator tożsamości wczytanej przez aplikację, a z `callable` wartość zwróconą przez źródło. Bez opcji każda paczka ma `user: null`. Pole wchodzi do formatu `v: 4`, który nie został jeszcze wydany.

**Zależności zewnętrzne:** odbiorca przyjmuje `v: 4` z polem `user` ([spec 02 §7](../spec/02-format-paczki.md#7-próbkowanie-po-stronie-odbiorcy)). Zmiany workera są poza tym repozytorium.

## Zadania

- [x] (=) **YQM-60** Identyfikator użytkownika w nagłówku paczki
      Spec: [01 §5.7](../spec/01-zbieranie-danych.md#57-identyfikator-użytkownika), [02 §1](../spec/02-format-paczki.md#1-nagłówek) · ADR: [0015](../adr/0015-identyfikator-uzytkownika-w-paczce.md)
      Gotowe, gdy: test przez komponent z prawdziwym `yii\web\User` pokazuje identyfikator przy wczytanej tożsamości i `null` bez niej, bez wywołania `findIdentity()` i bez otwarcia sesji przez pakiet.
      Paczka nie zawierała dotąd danych użytkowników ([spec 00 §2](../spec/00-przeglad-i-zakres.md#2-główna-zasada)), więc zakres zmienia się przed kodem.
