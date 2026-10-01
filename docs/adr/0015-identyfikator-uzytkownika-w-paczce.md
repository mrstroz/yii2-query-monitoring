# ADR-0015: Identyfikator użytkownika w nagłówku paczki

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany |
| **Data** | 2026-10-01 |
| **Dotyczy** | Wysyłka paczek, [spec 01 §5.7](../spec/01-zbieranie-danych.md#57-identyfikator-użytkownika), format paczki, [spec 02 §1](../spec/02-format-paczki.md#1-nagłówek) i [§7](../spec/02-format-paczki.md#7-próbkowanie-po-stronie-odbiorcy) |

## Kontekst

Dashboard odbiorcy ma pokazywać, którzy użytkownicy aplikacji wykonują najwięcej zapytań. Paczka mówi, jaka akcja wykonała zapytania, ale nie dla kogo. Do tej pory paczka z zasady nie zawierała żadnych danych użytkowników ([spec 00 §2](../spec/00-przeglad-i-zakres.md#2-główna-zasada)). Identyfikator użytkownika to dana osobowa, choć pseudonimowa.

Yii trzyma zalogowanego użytkownika w komponencie `user` (`yii\web\User`). `User::getId()` wywołuje `getIdentity(true)`. Gdy aplikacja w tym żądaniu jeszcze nie sięgnęła po tożsamość, ta metoda czyta sesję, otwiera ją i wywołuje `findIdentity()`, czyli zwykle jedno zapytanie do bazy. `getIdentity(false)` zwraca tożsamość tylko wtedy, gdy aplikacja już ją wczytała.

Format `v: 4` ([ADR-0014](0014-probkowanie-calych-paczek-przed-adapterem.md)) nie został jeszcze wydany ani przyjęty przez żadnego odbiorcę, więc nowe pole może wejść do niego bez kolejnej wersji.

## Decyzja

Nagłówek każdej paczki `v: 4` ma pole `user`: tekst albo `null`. Pakiet wypełnia je tylko wtedy, gdy aplikacja włączy to opcją komponentu `user`:

- `null` (domyślnie): pole zawsze ma `null`, pakiet niczego nie odczytuje;
- `true`: identyfikator tożsamości, którą aplikacja już wczytała w komponencie `user` klasy `yii\web\User`, bez wczytywania jej przez pakiet;
- dowolny `callable` z konfiguracji: własne źródło, które dostaje gotową paczkę i zwraca identyfikator albo `null`.

Pakiet odczytuje wartość przy wysyłce każdej paczki, po decyzji próbkowania, przy wstrzymanym przyjmowaniu wpisów. Wyjątek źródła albo wartość niepoprawna daje `null` i paczka idzie dalej. Źródło ma własny, jeden na proces `Yii::error`, osobny od reszty pakietu.

## Konsekwencje

**Pozytywne:** odbiorca może grupować zapytania po użytkowniku. Domyślne źródło nie dodaje zapytań ani nie otwiera sesji. Aplikacja, która chce pełnych danych albo innego identyfikatora (skrót, id najemcy, użytkownik z joba), podaje `callable` bez dziedziczenia komponentu. Zapytania wykonane przez źródło nie trafiają do żadnej paczki, a pominięta przez próbkowanie paczka nie wywołuje źródła.

**Negatywne:** paczka może zawierać daną osobową. Odpowiedzialność za to, co zwraca źródło, ponosi aplikacja, a pakiet niczego nie hashuje. Log pakietu nigdy nie zawiera wartości zwróconej przez źródło ([spec 01 §5.7](../spec/01-zbieranie-danych.md#57-identyfikator-użytkownika)). Przy `true` żądanie, które nie sięgnęło po tożsamość, ma `user: null`, choć ktoś był zalogowany. Większość aplikacji sięga po nią w kontroli dostępu albo w layoucie. Pole wydłuża każdą linię JSON o `"user":null,` i kolektor rezerwuje dla niego miejsce, gdy opcja jest włączona. Błąd źródła zajmuje jego własny wpis w logu, więc kolejny błąd źródła w tym procesie nie jest logowany. Strażnik źródła loguje komunikat tylko odrzuconej wartości; wyjątek samego źródła, także `InvalidConfigException` aplikacji, ma w logu tylko nazwę klasy.

**Wymagania:** identyfikator ma najwyżej 66 bajtów w JSON z `QueryBatch::JSON_FLAGS` razem z cudzysłowami, np. 64 znaki ASCII bez `"` i `\`; znaki poprzedzane w JSON przez `\` i znaki wielobajtowe zajmują więcej. Liczba całkowita i obiekt `Stringable` (np. `ObjectId` z `yii2-mongodb`) są zapisywane jako tekst, a tekst, który nie jest poprawnym UTF-8, daje `null`. Dłuższy identyfikator daje `null`, nie jest obcinany, bo obcięty identyfikator łączyłby różnych użytkowników. Odbiorca traktuje `user` jako wymiar, bez wagi.

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| Domyślnie `User::getId()`, także jako zalecany `callable` | Pełne dane, ale w żądaniu, które nie użyło tożsamości, pakiet przy wysyłce otwiera sesję, odświeża albo kończy logowanie, loguje z ciasteczka i wykonuje `findIdentity()`, czasem po wysłaniu nagłówków. Zmienia zachowanie aplikacji i dodaje zapytanie |
| Identyfikator z sesji (`__id`) bez tożsamości | Bez zapytania do bazy, ale pakiet sam otwiera sesję i podaje identyfikator, którego aplikacja nie zweryfikowała (wygasła sesja, usunięty użytkownik) |
| Nadpisanie przez podklasę komponentu | Właściciel projektu chciał zmiany w konfiguracji, a nie w kodzie. `callable` daje to samo bez dziedziczenia, a podklasa, która mimo to chce własnego źródła, ustawia `user` w swojej konfiguracji |
| Odczyt w `EVENT_AFTER_REQUEST` raz na proces | Nie obejmuje paczek `console` i `job` wysyłanych w trakcie, a źródło wywołane poza wstrzymanym przyjmowaniem mogłoby dodać swoje zapytania do paczki |
| Hashowanie identyfikatora przez pakiet | Odbiorca i tak potrzebuje mapowania na użytkownika. Aplikacja, która chce skrótu, zwraca go ze źródła |
| Wspólny `Guard` z resztą pakietu | Źródło rzucające przy każdej paczce zajęłoby jedyny wpis w logu procesu i ukryło późniejszy błąd adaptera |
| `v: 5` | `v: 4` nie jest jeszcze wydane, a druga zmiana wersji przed pierwszym wydaniem dałaby odbiorcy dwie gałęzie zamiast jednej |

## Kiedy wrócić do tej decyzji

Gdy odbiorca będzie potrzebował więcej niż jednego identyfikatora (np. użytkownik i najemca) albo gdy ochrona danych będzie wymagała, żeby pakiet sam usuwał lub hashował identyfikator.
