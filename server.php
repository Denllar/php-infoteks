<?php

class GeoServer {
    private $cities = array();
    
    public function __construct() {
        $this->loadCities();
    }
    
    private function loadCities() {
        // Увеличиваем лимит памяти
        ini_set('memory_limit', '256M');
        
        $file = fopen('RU.txt', 'r');
        if ($file === false) {
            die('Ош');
        }

        while (($line = fgets($file)) !== false) {
            $data = explode("\t", trim($line));
            // Сохраняем только необходимые данные
            if (count($data) >= 18) { // Проверка на корректность данных
                $city = [
                    'geonameid' => $data[0],
                    'name' => $data[1],
                    'asciiname' => $data[2],
                    'alternatenames' => $data[3],
                    'latitude' => (float)$data[4],
                    'longitude' => (float)$data[5],
                    'population' => (int)$data[14],
                    'timezone' => $data[17]
                ];
                $this->cities[$city['geonameid']] = $city;
            }
        }
        fclose($file);
    }
    
    // Метод 1: Получение информации о городе по ID
    public function getCityById($geonameid) {
        return isset($this->cities[$geonameid]) 
            ? $this->cities[$geonameid] 
            : ['error' => 'Город не найден'];
    }
    
    // Метод 2: Получение списка городов с пагинацией
    public function getCities($page, $perPage) {
        try {
            // Проверяем валидность параметров
            $page = max(1, (int)$page);
            $perPage = max(1, min(100, (int)$perPage));
            
            // Получаем только нужную порцию данных
            $cities = [];
            $counter = 0;
            $start = ($page - 1) * $perPage;
            $end = $start + $perPage;
            
            $file = fopen('RU.txt', 'r');
            if ($file === false) {
                throw new Exception('Не удалось открыть файл RU.txt');
            }
            
            while (($line = fgets($file)) !== false) {
                if ($counter >= $start && $counter < $end) {
                    $data = explode("\t", trim($line));
                    if (count($data) >= 18) {
                        $cities[] = [
                            'geonameid' => $data[0],
                            'name' => $data[1],
                            'asciiname' => $data[2],
                            'latitude' => (float)$data[4],
                            'longitude' => (float)$data[5],
                            'population' => (int)$data[14],
                            'timezone' => $data[17]
                        ];
                    }
                }
                $counter++;
                if ($counter >= $end) {
                    break;
                }
            }
            
            
            fclose($file);
            
            return [
                'page' => $page,
                'per_page' => $perPage,
                'data' => $cities
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    // Метод 3: Сравнение двух городов
    public function compareCities($city1Name, $city2Name) {
        $city1 = $this->findCityByRussianName($city1Name);
        $city2 = $this->findCityByRussianName($city2Name);
        
        if (!$city1 || !$city2) {
            return array(
                'error' => 'Один или оба города не найдены',
                'city1_found' => $city1 ? true : false,
                'city2_found' => $city2 ? true : false
            );
        }
        
        // Определяем какой город севернее
        $northernCity = $city1['latitude'] > $city2['latitude'] ? $city1['name'] : $city2['name'];
        $latitudeDiff = abs($city1['latitude'] - $city2['latitude']);
        
        // Проверяем временные зоны
        $sameTimezone = $city1['timezone'] === $city2['timezone'];
        $timezoneDiff = 0;
        
        if (!$sameTimezone) {
            $tz1 = new DateTimeZone($city1['timezone']);
            $tz2 = new DateTimeZone($city2['timezone']);
            $dt = new DateTime('now');
            $timezoneDiff = ($tz1->getOffset($dt) - $tz2->getOffset($dt)) / 3600;
        }
        
        return array(
            'Город1' => array(
                'Город' => $city1['name'],
                'Широта' => $city1['latitude'],
                'Долгота' => $city1['longitude'],
                'Часовой пояс' => $city1['timezone'],
                'Население' => $city1['population']
            ),
            'Город2' => array(
                'Город' => $city2['name'],
                'Широта' => $city2['latitude'], 
                'Долгота' => $city2['longitude'],
                'Часовой пояс' => $city2['timezone'],
                'Население' => $city2['population']
            ),
            'Сравнение' => array(
                'Северный город' => $northernCity,
                'Разница широты (км)' => round($latitudeDiff * 111.32, 2),
                'Тот же часовой пояс' => $sameTimezone,
                'Разница часового пояса (часы)' => $timezoneDiff
            )
        );
    }
    

    private function findCityByRussianName($name) {
        $candidates = array();
        foreach ($this->cities as $city) {
            // Проверяем основное название
            if ($city['name'] === $name) {
                $candidates[] = $city;
                continue;
            }
            
            // Проверяем альтернативные названия
            $alternateNames = explode(',', $city['alternatenames']);
            if (in_array($name, $alternateNames)) {
                $candidates[] = $city;
            }
        }
        
        if (empty($candidates)) {
            return null;
        }
        
        // Используем метод класса для сортировки
        usort($candidates, array($this, 'sortByPopulation'));
        return $candidates[0];
    }
    
    // Добавляем метод для сортировки
    private function sortByPopulation($a, $b) {
        return $b['population'] - $a['population'];
    }
    
    public function searchCities($query) {
        if (empty($query) || strlen($query) < 2) {
            return array('error' => 'Запрос должен быть не менее 2 символов');
        }
        
        $results = array();
        $query = strtolower(trim($query));
        
        foreach ($this->cities as $city) {
            if (stripos($city['name'], $query) === 0) {
                $results[] = array(
                    'id' => $city['geonameid'],
                    'name' => $city['name'],
                    'population' => $city['population']
                );
                continue;
            }
            
            $altNames = explode(',', $city['alternatenames']);
            foreach ($altNames as $altName) {
                if (stripos(trim($altName), $query) === 0) {
                    $results[] = array(
                        'id' => $city['geonameid'],
                        'name' => $city['name'],
                        'alt_name' => $altName,
                        'population' => $city['population']
                    );
                    break;
                }
            }
        }
        
        // Используем тот же метод сортировки
        usort($results, array($this, 'sortByPopulation'));
        
        return array(
            'query' => $query,
            'found' => count($results),
            'results' => array_slice($results, 0, 20)
        );
    }
}

// Обработка HTTP-запросов
$server = new GeoServer();

// Устанавливаем заголовки для JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Получаем параметры запроса
$query = $_GET;

// Определяем тип запроса на основе параметров
if (isset($query['id'])) {
    // Метод 1: Получение города по ID
    echo json_encode($server->getCityById($query['id']));
} 
elseif (isset($query['page']) || isset($query['per_page'])) {
    // Метод 2: Список городов с пагинацией
    $page = isset($query['page']) ? (int)$query['page'] : 1;
    $perPage = isset($query['per_page']) ? (int)$query['per_page'] : 10;
    echo json_encode($server->getCities($page, $perPage));
} 
elseif (isset($query['city1']) && isset($query['city2'])) {
    // Метод 3: Сравнение городов
    echo json_encode($server->compareCities($query['city1'], $query['city2']));
} 
elseif (isset($query['q'])) {
    // Метод 4: Поиск городов
    echo json_encode($server->searchCities($query['q']));
} 
else {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid request',
        'usage' => [
            'Get city by ID' => '?id=524901',
            'Get cities list' => '?page=1&per_page=10',
            'Compare cities' => '?city1=Москва&city2=Санкт-Петербург',
            'Search cities' => '?q=Мос'
        ]
    ]);
}