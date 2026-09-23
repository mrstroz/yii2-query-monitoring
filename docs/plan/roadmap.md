# Roadmap

## Stan na dziś

| Pole | Wartość |
|---|---|
| **Etap** | E3. Źródło MongoDB — zadania jeszcze nie spisane, numeracja od YQM-27. E2 zakończony, 8 z 8 |
| **Ostatnio ukończone** | [YQM-26](03-testy.md): odbiór E2. `composer test`, `stan` i `cs` zielone na PHP 8.1.34 i 8.4.25, obie bazy w jednym biegu (`failOnSkipped` nie pozwala inaczej); audyt powtórzony tym samym filtrem daje 49 metod parametryzowanych bazą, z czego dziewięć do ręcznej lektury i żadnej bez nazwanego powodu; `ANY_DB` stoi w dziewięciu zredukowanych metodach w sześciu klasach; lista przypadków zgodna z baseline poza dziewięcioma wierszami tabeli. Pliki baseline usunięte, historia je trzyma |
| **Następne** | [YQM-27](04-mongodb.md): pierwsze zadanie E3, sonda dostępu do `Manager` w `yii\mongodb\Connection`. Testy pisze się według [`tests/README.md`](../../tests/README.md) — o katalogu decyduje kontrakt, a rodzaj bazy parametryzuje się tylko wtedy, gdy asercja czyta wynik kodu podpiętego pod sterownik |

Tę tabelę podmienia ten, kto kończy zadanie. To jedyne miejsce, w które trzeba zajrzeć na początku sesji.

## Etapy

| Etap | Plik | Cel | Co działa na końcu | Postęp |
|---|---|---|---|---|
| **E0** | [01-fundament-i-sql](01-fundament-i-sql.md) | Pakiet, kolektor, `Command`, cykl życia HTTP | Aplikacja z MySQL i PostgreSQL daje paczkę do jawnego adaptera, wyjątek nie gubi paczki | 12/12 |
| **E1** | [02-adapter-plikowy](02-adapter-plikowy.md) | Adapter plikowy z rotacją | Paczki w `runtime/logs`, bezpieczne przy 8 procesach | 6/6 |
| **E2** | [03-testy](03-testy.md) | Architektura i konwencje testów | Cztery testsuite'y, konwencje spisane i zastosowane, żaden scenariusz nie zniknął | 8/8 |
| **E3** | [04-mongodb](04-mongodb.md) | Źródło MongoDB | Jedna paczka z wpisami SQL i MongoDB | – |
| **E4** | [05-konsola](05-konsola.md) | Zadania konsolowe | Wiele paczek z `seq` w jednym procesie | – |
| **E5** | [06-wydajnosc-i-odbior](06-wydajnosc-i-odbior.md) | Test wydajności, dokumentacja | Narzut w progu, limity potwierdzone, README pakietu | – |

26 zadań spisanych. Jedno zadanie to jedna sesja i jeden commit.

## Dlaczego w tej kolejności

E0 idzie pierwsze, bo podmiana klasy `Command` to założenie, na którym stoi cała reszta. Jeśli omija jakąś ścieżkę Yii albo nie widzi cache, trzeba to wiedzieć przed adapterem i MongoDB. Dlatego YQM-10 jest w E0, nie w etapie testów.

E1 przed resztą, bo bez adaptera plikowego nie da się obejrzeć paczek w prawdziwej aplikacji, a MongoDB wymaga sondy dostępu do `Manager`, która może zmienić spec 01 §3. Lepiej mieć działający pakiet dla aplikacji tylko z SQL, zanim ta sonda się rozstrzygnie.

E2 przed E3 i E4, bo zestaw testów po dwóch etapach przestał mieć jedną zasadę podziału, a MongoDB i konsola dokładają dwa nowe obszary testów. Uporządkowanie po nich kosztowałoby tyle samo pracy w trzech miejscach zamiast w jednym, więc nowe źródło danych i tryb konsolowy zaczynają już w jednej strukturze.

E4 po E3, bo limity konsoli i `seq` mają sens dopiero z oboma źródłami. E5 na końcu, bo test wydajności mierzy całość z normalizacją i zapisem, a limity w spec są wartościami początkowymi do korekty tym pomiarem.

Efekt uboczny: do końca E2 pakiet nie ma MongoDB, więc demo w aplikacji z MongoDB pokaże tylko połowę zapytań.

## Ryzyka wyciągnięte przed kolejkę

| Ryzyko | Co robimy | Kiedy |
|---|---|---|
| Podmiana klasy `Command` omija jakąś ścieżkę Active Record albo raportuje trafienie w cache | Test integracyjny z AR i `Connection::cache()` | YQM-10, E0 |
| `exit(1)` z `ErrorHandler` gubi paczkę mimo callbacku shutdown | Test z nieobsłużonym wyjątkiem w osobnym procesie | YQM-8, E0 |
| `Command::prepare()` łączy `open()` i `pdo->prepare()`, więc pomiar samego `prepare` może wymagać nadpisania całej metody i psuć wiązanie parametrów lub konwersję wyjątków | Test wiązania, konwersji wyjątków i ponownego wykonania przygotowanego polecenia. Rozstrzygnięte w YQM-5: kopia metody z zegarem wokół `pdo->prepare()` | YQM-5, E0 |
| Blokada `.lock` nie chroni rotacji przy wielu procesach i dwie rotacje nadpisują `.1` | Test 8 procesów z rozliczeniem paczek utraconych przez zajętą blokadę. Rozstrzygnięte w YQM-18: blokada chroni rotację, bez niej test pada. Przy sztucznym obciążeniu (8 procesów, 2 ms przerwy, `maxFiles` 481) przepada około 40% paczek; rzeczywisty odsetek mierzy E5 | YQM-18, E1 |
| `yii\mongodb\Connection` nie daje dostępu do `Manager` bez podmiany klasy | Sonda jako pierwsze zadanie etapu | E3 |
| Normalizator literałów kosztuje więcej niż 5% czasu | Pomiar całości z normalizacją | E5 |

## Czego w planie nie ma

Lista w [spec 00 §4](../spec/00-przeglad-i-zakres.md#4-poza-zakresem-wersji-1). Agregaty i próbkowanie nie wrócą bez zmiany [ADR 0002](../adr/0002-plaska-lista-zamiast-agregatow.md). Rozpoznawanie jobów kolejki wymaga zmiany [ADR 0007](../adr/0007-zadanie-konsolowe-to-jeden-proces.md).
