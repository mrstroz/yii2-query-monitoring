# Roadmap

## Stan na dziś

| Pole | Wartość |
|---|---|
| **Etap** | E0. Fundament i źródło SQL, zakończony, 12 z 12. E1 czeka na spisanie zadań |
| **Ostatnio ukończone** | [YQM-10, YQM-11](01-fundament-i-sql.md): test integracyjny Active Record, cache, dwa połączenia i savepointy przy wyłączonym logowaniu i profilowaniu Yii; CI z MySQL i PostgreSQL jako services (pominięty test = czerwony przebieg), `README.md` z przykładem konfiguracji i instrukcją aplikacji testowej. Workflow sprawdzony `actionlint` i odtworzeniem kroków w compose; pierwszy run na GitHub niepotwierdzony do pierwszego pusha |
| **Następne** | Spisać zadania [E1. Adapter plikowy](02-adapter-plikowy.md) z celu i obowiązkowych scenariuszy odbioru w tym pliku, numeracja od YQM-13. Komendy przez Docker: `docker compose run --rm php composer test` (bazy startują same) |

Tę tabelę podmienia ten, kto kończy zadanie. To jedyne miejsce, w które trzeba zajrzeć na początku sesji.

## Etapy

| Etap | Plik | Cel | Co działa na końcu | Postęp |
|---|---|---|---|---|
| **E0** | [01-fundament-i-sql](01-fundament-i-sql.md) | Pakiet, kolektor, `Command`, cykl życia HTTP | Aplikacja z MySQL i PostgreSQL daje paczkę do jawnego adaptera, wyjątek nie gubi paczki | 12/12 |
| **E1** | [02-adapter-plikowy](02-adapter-plikowy.md) | Adapter plikowy z rotacją | Paczki w `runtime/logs`, bezpieczne przy 8 procesach | – |
| **E2** | [03-mongodb](03-mongodb.md) | Źródło MongoDB | Jedna paczka z wpisami SQL i MongoDB | – |
| **E3** | [04-konsola](04-konsola.md) | Zadania konsolowe | Wiele paczek z `seq` w jednym procesie | – |
| **E4** | [05-wydajnosc-i-odbior](05-wydajnosc-i-odbior.md) | Test wydajności, dokumentacja | Narzut w progu, limity potwierdzone, README pakietu | – |

12 zadań spisanych. Jedno zadanie to jedna sesja i jeden commit.

## Dlaczego w tej kolejności

E0 idzie pierwsze, bo podmiana klasy `Command` to założenie, na którym stoi cała reszta. Jeśli omija jakąś ścieżkę Yii albo nie widzi cache, trzeba to wiedzieć przed adapterem i MongoDB. Dlatego YQM-10 jest w E0, nie w etapie testów.

E1 przed E2, bo bez adaptera plikowego nie da się obejrzeć paczek w prawdziwej aplikacji, a MongoDB wymaga sondy dostępu do `Manager`, która może zmienić spec 01 §3. Lepiej mieć działający pakiet dla aplikacji tylko z SQL, zanim ta sonda się rozstrzygnie.

E3 po E2, bo limity konsoli i `seq` mają sens dopiero z oboma źródłami. E4 na końcu, bo test wydajności mierzy całość z normalizacją i zapisem, a limity w spec są wartościami początkowymi do korekty tym pomiarem.

Efekt uboczny: do końca E1 pakiet nie ma MongoDB, więc demo w aplikacji z MongoDB pokaże tylko połowę zapytań.

## Ryzyka wyciągnięte przed kolejkę

| Ryzyko | Co robimy | Kiedy |
|---|---|---|
| Podmiana klasy `Command` omija jakąś ścieżkę Active Record albo raportuje trafienie w cache | Test integracyjny z AR i `Connection::cache()` | YQM-10, E0 |
| `exit(1)` z `ErrorHandler` gubi paczkę mimo callbacku shutdown | Test z nieobsłużonym wyjątkiem w osobnym procesie | YQM-8, E0 |
| `Command::prepare()` łączy `open()` i `pdo->prepare()`, więc pomiar samego `prepare` może wymagać nadpisania całej metody i psuć wiązanie parametrów lub konwersję wyjątków | Test wiązania, konwersji wyjątków i ponownego wykonania przygotowanego polecenia. Rozstrzygnięte w YQM-5: kopia metody z zegarem wokół `pdo->prepare()` | YQM-5, E0 |
| `yii\mongodb\Connection` nie daje dostępu do `Manager` bez podmiany klasy | Sonda jako pierwsze zadanie etapu | E2 |
| Normalizator literałów kosztuje więcej niż 5% czasu | Pomiar całości z normalizacją | E4 |

## Czego w planie nie ma

Lista w [spec 00 §4](../spec/00-przeglad-i-zakres.md#4-poza-zakresem-wersji-1). Agregaty i próbkowanie nie wrócą bez zmiany [ADR 0002](../adr/0002-plaska-lista-zamiast-agregatow.md). Rozpoznawanie jobów kolejki wymaga zmiany [ADR 0007](../adr/0007-zadanie-konsolowe-to-jeden-proces.md).
