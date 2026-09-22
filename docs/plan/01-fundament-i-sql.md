# E0. Fundament i źródło SQL

**Cel:** pakiet Composer, który w aplikacji Yii 2 z MySQL lub PostgreSQL zbiera listę zapytań z żądania HTTP i przekazuje paczkę do jawnie podanego adaptera.

**Koniec etapu:** testowa aplikacja Yii z Active Record wykonuje zapytania na MySQL i na PostgreSQL, a adapter dostaje jedną paczkę z listą wpisów bez wartości parametrów. Wyjątek z zapytania nie gubi paczki. Przykład konfiguracji i instrukcja uruchomienia aplikacji testowej są w repozytorium. MongoDB i adapter plikowy nie są częścią tego etapu.

**Zależności zewnętrzne:** brak.

## Zadania

- [ ] (^) **YQM-1** Szkielet pakietu Composer z PHPUnit, PHPStan i PHP CS Fixer
      Spec: [00 §7](../spec/00-przeglad-i-zakres.md#7-środowiska)
      Gotowe, gdy: `composer test`, `composer stan` i `composer cs` działają na pustym pakiecie, a CI w GitHub Actions uruchamia je na PHP 8.1 i 8.4.
      Rozstrzyga otwartą kwestię 1 w spec 00 §9: nazwę pakietu i namespace.

- [ ] (=) **YQM-2** Klasa `QueryBatch` z nagłówkiem i wpisami, serializacja do JSON, interfejs adaptera
      Spec: [02 §1](../spec/02-format-paczki.md#1-nagłówek) · [03 §1](../spec/03-adaptery-wyjsciowe.md#1-kontrakt) · Zależy od: YQM-1
      Gotowe, gdy: JSON z przykładu w spec 02 §3 powstaje z obiektu i przechodzi porównanie w teście, a `BatchAdapterInterface::send()` jest zdefiniowany.

- [ ] (^) **YQM-3** Kolektor w pamięci z limitem wpisów i rozmiaru
      Spec: [02 §5](../spec/02-format-paczki.md#5-limity) · Zależy od: YQM-2
      Gotowe, gdy: 600 wpisów daje paczkę z 500 wpisami i `dropped: 100`, limit bajtów jest liczony dla końcowego JSON z nagłówkiem i `dropped`, a wpis `result: error` po limicie też zwiększa licznik.

- [ ] (^) **YQM-12** Aplikacja testowa Yii z Active Record, adapter przechwytujący i uruchamianie testów w osobnym procesie
      Spec: [00 §7](../spec/00-przeglad-i-zakres.md#7-środowiska) · Zależy od: YQM-2
      Gotowe, gdy: test PHPUnit uruchamia aplikację w osobnym procesie PHP, odczytuje paczkę zapisaną przez adapter przechwytujący i sprawdza jej treść, a model AR ma schemat tworzony przy starcie testu.
      Istnieje, bo YQM-8 sprawdza prawdziwe `exit()` i shutdown, czego nie da się zrobić w procesie PHPUnit.

- [ ] (=) **YQM-4** Komponent Yii z konfiguracją, bootstrapem, nagłówkiem paczki i podpięciem listy połączeń
      Spec: [01 §1](../spec/01-zbieranie-danych.md#1-komponent-i-konfiguracja) · [02 §1](../spec/02-format-paczki.md#1-nagłówek) · Zależy od: YQM-3, YQM-12
      Gotowe, gdy: nagłówek ma losowe `id`, `app`, `host`, `ts` w UTC i `seq: 1`, połączenie spoza listy nie daje wpisów, `admin/db` z modułu jest znajdowane, a `enabled: false` nie podmienia `commandClass` i nie wywołuje adaptera.
      W E0 konfiguracja obsługuje tylko SQL i jawnie podany adapter. Klucze `file` i połączenia MongoDB dochodzą w E1 i E2.

- [ ] (^) **YQM-5** Klasa `Command` mierząca `PDO::prepare()` i `PDOStatement::execute()`
      Spec: [01 §2](../spec/01-zbieranie-danych.md#2-źródło-sql) · ADR: [0001](../adr/0001-podmiana-klasy-command-zamiast-profilera.md) · Zależy od: YQM-4
      Gotowe, gdy: wyjątek z `prepare()` i z `execute()` daje wpis `result: error` z SQLSTATE i przechodzi przez konwersję wyjątków Yii bez zmian, ponowienie w `internalExecute()` daje dwa wpisy, drugie wykonanie tego samego przygotowanego polecenia nie dolicza czasu przygotowania, wiązanie parametrów działa jak w `yii\db\Command`, a `Connection::open()` i pobranie wyników nie wchodzą w `time_ms`.
      Sonda ryzyka: `Command::prepare()` w Yii 2.0.45 robi `open()` i `pdo->prepare()` w jednej metodzie. Zadanie ustala, jak zmierzyć samo `pdo->prepare()`.

- [ ] (=) **YQM-6** Normalizator SQL z dialektami MySQL i PostgreSQL
      Spec: [02 §4](../spec/02-format-paczki.md#4-normalizacja) · ADR: [0004](../adr/0004-normalizacja-literalow-na-znak-zapytania.md) · Zależy od: YQM-1
      Gotowe, gdy: testy tabelaryczne pokrywają literały, komentarze, `LIMIT`, niedomknięty literał dający `null`, `SELECT "email" FROM "users"` z identyfikatorami dla `pgsql` i dwoma `?` dla `mysql`, a obcięcie daje najwyżej 2048 bajtów razem z `…`, poprawne UTF-8 i następuje po normalizacji.
      Rozstrzyga otwartą kwestię 4 w spec 00 §9.

- [ ] (=) **YQM-7** Akcja wejściowa z `EVENT_BEFORE_ACTION` w nagłówku
      Spec: [01 §4](../spec/01-zbieranie-danych.md#4-żądanie-http) · Zależy od: YQM-4
      Gotowe, gdy: kontroler w głównej aplikacji daje `module: null`, kontroler w module zagnieżdżonym daje `module: "admin/orders"` z lokalnymi `controller` i `action`, zagnieżdżone `runAction` i `site/error` nie nadpisują pierwszej akcji, a 404 przed routingiem ze skonfigurowanym `errorAction` daje trzy `null`.

- [ ] (=) **YQM-8** Finalizacja w `EVENT_AFTER_REQUEST` i w callbacku shutdown
      Spec: [01 §4](../spec/01-zbieranie-danych.md#4-żądanie-http) · ADR: [0003](../adr/0003-finalizacja-w-after-request-i-shutdown.md) · Zależy od: YQM-4, YQM-12
      Gotowe, gdy: nieobsłużony wyjątek zakończony przez `ErrorHandler` z `exit(1)`, zwykłe `exit()` w akcji i zwykłe żądanie dają dokładnie jedną paczkę w osobnym procesie, żądanie bez zapytań nie daje żadnej, a zapytanie po finalizacji nie daje wpisu ani drugiej paczki.

- [ ] (=) **YQM-9** Ochrona aplikacji: obsługa błędu adaptera, błędu monitoringu i flaga ponownego wejścia
      Spec: [01 §6](../spec/01-zbieranie-danych.md#6-ochrona-aplikacji) · ADR: [0006](../adr/0006-blad-adaptera-gubi-paczke.md) · Zależy od: YQM-8
      Gotowe, gdy: adapter rzucający wyjątek nie zmienia odpowiedzi i nie jest wywołany drugi raz w shutdown, wymuszony błąd w kolektorze, normalizatorze i serializacji po udanym zapytaniu nie zmienia jego wyniku, przy błędzie bazy aplikacja dostaje oryginalny wyjątek Yii, `Yii::error` pojawia się raz na proces bez zapętlenia, a zapytanie wewnątrz adaptera nie daje wpisu.

- [ ] (=) **YQM-10** Test integracyjny: Active Record, cache, kilka połączeń i savepointy przez podmienione `Command`
      Spec: [00 §6](../spec/00-przeglad-i-zakres.md#6-kryteria-sukcesu) · Zależy od: YQM-5, YQM-6, YQM-7, YQM-9
      Gotowe, gdy: `Model::find()->all()` daje wpis z `query` bez wartości, drugie wywołanie w `Connection::cache()` nie daje wpisu, dwa monitorowane połączenia dają wpisy z różnym `conn` w kolejności zakończenia, zagnieżdżona transakcja daje wpisy savepointów, a `enableProfiling` i `enableLogging` są w teście wyłączone.
      Sonda ryzyka: podmiana klasy może omijać jakąś ścieżkę Yii. Ten test to sprawdza przed budową reszty.

- [ ] (=) **YQM-11** Cały zestaw testów E0 na MySQL i PostgreSQL w CI przez Docker, przykład konfiguracji i instrukcja aplikacji testowej
      Spec: [00 §7](../spec/00-przeglad-i-zakres.md#7-środowiska) · Zależy od: YQM-10
      Gotowe, gdy: workflow CI uruchamia `composer test` z testami zależnymi od bazy na obu bazach i przechodzi, a `README.md` pakietu ma przykład konfiguracji dla SQL z jawnym adapterem i instrukcję uruchomienia aplikacji testowej.
      Etap nie zamyka się bez tego zadania. SQLite nie potwierdza pomiaru ani normalizacji na deklarowanych bazach.

## Uwagi

Do YQM-11 testy integracyjne mogą używać SQLite przez `yii\db\Connection` do szybkiego cyklu lokalnego. Etap zamyka YQM-11 na MySQL i PostgreSQL. Normalizator z YQM-6 ma dialekty w testach jednostkowych od razu.

YQM-12 ma numer wyższy od pozycji na liście, bo doszedł po spisaniu etapu. Numery nie są przenumerowywane.
