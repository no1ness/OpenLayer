
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ol@v9.2.4/ol.css">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@3.0.0/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/ol@v9.2.4/dist/ol.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
          .map {
            height: 100vh;
            width: 100%;
            position: relative;
        }
        #context-menu {
            display: none;
            position: absolute;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 10;
            padding: 0.5rem;
        }
        #context-menu ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        #context-menu li {
            padding: 0.5rem;
            cursor: pointer;
            border-bottom: 1px solid #d1d5db;
            transition: background-color 0.2s ease-in-out;
        }
        #context-menu li:last-child {
            border-bottom: none;
        }
        #context-menu li:hover {
            background-color: #f3f4f6;
            color: #1f2937;
        }
    </style>
<div id="map" class="map flex flex-col items-center justify-center h-screen">
        <div class="absolute bottom-0 left-1/2 transform -translate-x-1/2 mb-4 flex justify-center" style="z-index: 1;">
            <select id="draw-type" class="btn btn-error mx-2">
                <option value="None">-</option>
                <option value="Point">Точка</option>
                <option value="LineString">Линия</option>
                <option value="Polygon">Полигон</option>
            </select>
            <button id="delete-button" type="button" class="btn btn-error mx-2">Удалить</button>
            <button id="edit-button" type="button" class="btn btn-error mx-2">Редактировать</button>
            <button id="cancel-button" type="button" class="btn btn-error mx-2">Отмена</button>
            <button id="finish-button" type="button" class="btn btn-error mx-2">Завершить</button>
            <button id="move-button" type="button" class="btn btn-error mx-2">Переместить</button>
        </div>
    </div>
    <div class="bg-blue-500 text-white p-4">
    TailwindCSS работает!
</div>
    <div id="context-menu" class="shadow-lg bg-white border border-gray-300 rounded-md">
        <ul>
            <li id="context-create">Создать</li>
            <li id="context-delete">Удалить</li>
            <li id="context-edit">Редактировать</li>
        </ul>
    </div>
    <script>
        // Инициализация карты и слоев
        function initializeMap() {
            const extent = [0, 0, 3500, 1750];
            const projection = new ol.proj.Projection({
                code: 'xkcd-image',
                units: 'pixels',
                extent: extent
            });

            const map = new ol.Map({
                target: 'map',
                view: new ol.View({
                    projection: projection,
                    center: ol.extent.getCenter(extent),
                    zoom: 2,
                    maxZoom: 8,
                    extent: extent
                }),
                layers: [
                    new ol.layer.Image({
                        source: new ol.source.ImageStatic({
                            url: 'https://kp.vevanta.com/storage/renders/87/SKtxTV166bfejtaY23OWGOe2Jn53hDc5t4FV034v.jpg',
                            projection: projection,
                            imageExtent: extent
                        })
                    })
                ]
            });

            map.getView().fit(extent, { size: map.getSize() });
            return map;
        }

        // Загрузка полигонов с сервера
        function loadPolygons(drawSource, polygonCache) {
            fetch('https://kp.vevanta.com/api/client/kp/renders/87')
                .then(response => response.json())
                .then(data => {
                    data.item.polygons.forEach(item => {
                        const coordinates = item.polygon_data.map(coord => [coord.lng, coord.lat]);
                        const polygon = new ol.geom.Polygon([coordinates]);
                        const feature = new ol.Feature(polygon);
                        feature.set('item', { id: item.id, polygon_data: item.polygon_data });
                        drawSource.addFeature(feature);
                        polygonCache[item.id] = feature;
                    });
                })
                .catch(error => console.error('Ошибка при загрузке полигонов:', error));
        }

        // Создание стиля для полигона
        function createPolygonStyle(coordinates, highlightMode) {
            const color = {
                edit: 'rgba(0, 128, 0, 0.5)',  // Зеленый для редактирования
                highlight: 'rgba(255, 165, 0, 0.5)',  // Оранжевый для выделения
                move: 'rgba(255, 0, 0, 0.5)'  // Красный для перемещения
            }[highlightMode || 'highlight']; // По умолчанию - оранжевый для выделения

            const styles = [
                new ol.style.Style({
                    stroke: new ol.style.Stroke({ color: color, width: 2 }),
                    fill: new ol.style.Fill({ color: color })
                })
            ];

            // Выделение всех точек полигона
            coordinates.forEach((coordinate) => {
                styles.push(new ol.style.Style({
                    image: new ol.style.Circle({
                        radius: 5,
                        fill: new ol.style.Fill({ color: 'blue' }),
                        stroke: new ol.style.Stroke({ color: 'white', width: 2 })
                    }),
                    geometry: new ol.geom.Point(coordinate)
                }));
            });

            return styles;
        }

        // Создание взаимодействия рисования
        function createDrawInteraction(type, drawSource) {
            const drawInteraction = new ol.interaction.Draw({
                source: drawSource,
                type: type
            });

            drawInteraction.on('drawend', function(event) {
                const feature = event.feature;
                feature.setStyle(createPolygonStyle(feature.getGeometry().getCoordinates()[0]));
                select.getFeatures().clear();
                select.getFeatures().push(feature);
                // Переключение в нормальный режим
                drawTypeSelect.value = 'None';
                drawInteraction.setActive(false);
                console.log(`Полигон создан: ${JSON.stringify(feature.get('item'))}`);
            });

            return drawInteraction;
        }

      // Создание взаимодействия модификации
let activeModifyInteraction = null;
let highlightedPolygons = [];

function createModifyInteraction(feature) {
    if (!feature) {
        console.error('Feature is undefined');
        return;
    }

    // Удаление старого стиля
    highlightedPolygons.forEach((polygon) => {
        polygon.setStyle(null);
    });
    highlightedPolygons = [];

    activeModifyInteraction = new ol.interaction.Modify({
        features: new ol.Collection([feature])
    });

    const coordinates = feature.getGeometry().getCoordinates()[0];
    const polygonStyle = createPolygonStyle(coordinates, 'edit');
    feature.setStyle(polygonStyle);
    highlightedPolygons.push(feature);

    console.log(`Выделено ${coordinates.length} точек в полигоне`);

    activeModifyInteraction.on('modifyend', (event) => {
        const modifiedFeature = event.features.getArray()[0];
        const coordinates = modifiedFeature.getGeometry().getCoordinates()[0];
        modifiedFeature.setStyle(createPolygonStyle(coordinates, 'edit'));
    });

    return activeModifyInteraction;
}

        // Завершение редактирования
        function finishActiveModification() {
            if (activeModifyInteraction) {
                map.removeInteraction(activeModifyInteraction);
                activeModifyInteraction = null;
                select.getFeatures().clear(); // Очистка выбора
                highlightedPolygons.forEach((polygon) => {
                    polygon.setStyle(null);
                });
                highlightedPolygons = [];
            }
        }

        // Инициализация карты и взаимодействий
        const map = initializeMap();
        const drawSource = new ol.source.Vector({ wrapX: false });
        const vectorLayer = new ol.layer.Vector({ source: drawSource });
        map.addLayer(vectorLayer);
        const polygonCache = {};
        loadPolygons(drawSource, polygonCache);

        // Инициализация взаимодействия выделения
        const select = new ol.interaction.Select({
            condition: ol.events.condition.click,
            style: new ol.style.Style({
                stroke: new ol.style.Stroke({ color: 'rgba(255, 165, 0, 0.5)', width: 2 }), // Оранжевый для выделения
                fill: new ol.style.Fill({ color: 'rgba(255, 165, 0, 0.5)' })
            })
        });
        map.addInteraction(select);

        // Инициализация взаимодействия рисования
        let drawInteraction = createDrawInteraction('Polygon', drawSource);
        drawInteraction.setActive(false);
        map.addInteraction(drawInteraction);

        // Получение элементов управления
        let contextMenuActiveFeature = null;
        const drawTypeSelect = document.getElementById('draw-type');
        const deleteButton = document.getElementById('delete-button');
        const editButton = document.getElementById('edit-button');
        const cancelButton = document.getElementById('cancel-button');
        const finishButton = document.getElementById('finish-button');
        const moveButton = document.getElementById('move-button');
        const contextMenu = document.getElementById('context-menu');
        const contextDelete = document.getElementById('context-delete');
        const contextEdit = document.getElementById('context-edit');
        const contextCreate = document.getElementById('context-create');

        // Обработка изменения типа рисования
        let isEditingMode = false;
        drawTypeSelect.onchange = () => {
    const value = drawTypeSelect.value;
    if (value !== 'None') {
        // Если выбирается режим рисования, выключаем режим редактирования
        isEditingMode = false;
        map.getTargetElement().removeAttribute('data-remove-feature-id');
        map.removeInteraction(drawInteraction);
        drawInteraction = createDrawInteraction(value, drawSource);
        map.addInteraction(drawInteraction);
        drawInteraction.setActive(true);
        console.log(`Изменен тип рисования на ${value}`);
    } else {
        // Включаем режим редактирования, если выбран тип 'None'
        isEditingMode = true;
    }
};

        // Показать контекстное меню
        map.on('contextmenu', e => {
            e.preventDefault();
            const pixel = map.getEventPixel(e.originalEvent);
            const feature = map.forEachFeatureAtPixel(pixel, f => f);

            contextMenu.style.display = 'block';
            contextMenu.style.left = `${e.pixel[0]}px`;
            contextMenu.style.top = `${e.pixel[1]}px`;
            contextMenuActiveFeature = feature || null;
        });

        // Закрытие контекстного меню при клике вне его
        map.getViewport().addEventListener('click', () => {
            contextMenu.style.display = 'none';
        });

        // Обработка нажатия кнопки удаления в контекстном меню
        contextDelete.onclick = () => {
            if (contextMenuActiveFeature) {
                drawSource.removeFeature(contextMenuActiveFeature);
                const item = contextMenuActiveFeature.get('item');
                if (item && item.id) delete polygonCache[item.id];
                console.log('Удален полигон из контекстного меню:', item);
                contextMenu.style.display = 'none';
            }
        };

        // Обработка нажатия кнопки редактирования в контекстном меню
        contextEdit.onclick = () => {
            if (contextMenuActiveFeature) {
                if (activeModifyInteraction) {
                    if (!confirm('Вы уверены, что хотите начать редактирование нового полигона?')) {
                        return;
                    }
                    finishActiveModification(); // Завершение текущего редактирования
                }

                const feature = contextMenuActiveFeature;
                const modifyInteraction = createModifyInteraction(feature);

                map.addInteraction(modifyInteraction);

                // Сохранение активного взаимодействия модификации
                activeModifyInteraction = modifyInteraction;

                console.log('Начато редактирование полигона из контекстного меню:', feature.get('item'));

                finishButton.onclick = () => {
                    finishActiveModification();
                };

                contextMenu.style.display = 'none';
            }
        };

        // Обработка нажатия кнопки создания в контекстном меню
        contextCreate.onclick = () => {
            if (activeModifyInteraction) {
                if (!confirm('Вы уверены, что хотите начать создание нового полигона?')) {
                    return;
                }
                finishActiveModification(); // Завершение текущего редактирования
            }
            const selectedType = drawTypeSelect.value;

                // Активируем режим рисования полигона
                drawInteraction = createDrawInteraction('Polygon', drawSource);
                map.addInteraction(drawInteraction);
                drawInteraction.setActive(true);
                console.log('Начато создание нового полигона из контекстного меню');

            contextMenu.style.display = 'none';
        };

        deleteButton.addEventListener('click', () => {
            console.log('Delete button clicked');
            const selectedFeatures = select.getFeatures();
            selectedFeatures.forEach((feature) => {
                const id = feature.get('item').id;
                drawSource.removeFeature(feature);
                delete polygonCache[id];
            });
            select.getFeatures().clear();
        });

        // Обработчик начала редактирования полигона
        editButton.addEventListener('click', () => {
    const selectedFeatures = select.getFeatures();
    if (selectedFeatures.getLength() > 0) {
        const feature = selectedFeatures.getArray()[0];
        map.addInteraction(createModifyInteraction(feature));
        isEditingMode = true;
        console.log('Начато редактирование полигона из контекстного меню:', feature.get('item'));
        console.log('isEditingMode:', isEditingMode); // Логирование isEditingMode
    } else {
        console.log('Ошибка редактирования: нет выбранных полигонов');
    }
});

// Обработчик отмены редактирования полигона
cancelButton.addEventListener('click', () => {
    console.log('Cancel button clicked');
    if (activeModifyInteraction) {
        const selectedFeatures = select.getFeatures();
        if (selectedFeatures.getLength() > 0) {
            const feature = selectedFeatures.item(0);
            feature.getGeometry().setCoordinates([feature.get('originalCoordinates')]);
            feature.setStyle(null);
            map.removeInteraction(activeModifyInteraction);
            activeModifyInteraction = null;
            isEditingMode = false;
        }
    }
});

// Обработчик завершения редактирования полигона
finishButton.addEventListener('click', () => {
    console.log('Finish edit button clicked');
    const selectedFeatures = select.getFeatures();
    if (selectedFeatures.getLength() > 0) {
        const feature = selectedFeatures.getArray()[0];
        map.removeInteraction(modifyInteraction); // Удалите текущий modifyInteraction
        isEditingMode = false;
        console.log('Редактирование завершено:', feature.get('item'));
        console.log('isEditingMode:', isEditingMode); // Логирование isEditingMode
    } else {
        console.log('Ошибка завершения редактирования: нет выбранных полигонов');
    }
});



// Обработчик перемещения полигона
let activeTranslateInteraction = null; // Переменная для хранения активного translateInteraction
moveButton.addEventListener('click', () => {
    console.log('Move button clicked');
    const selectedFeatures = select.getFeatures();
    if (selectedFeatures.getLength() > 0) {
        const feature = selectedFeatures.item(0);

        if (activeTranslateInteraction) {
            // Если взаимодействие уже активно, выполняем логику завершения перемещения
            console.log('Finishing move interaction');
            map.removeInteraction(activeTranslateInteraction);
            activeTranslateInteraction = null;
            feature.setStyle(createPolygonStyle(feature.getGeometry().getCoordinates()[0]));
            select.getFeatures().clear(); // Снять выделение
        } else {
            // Если взаимодействие не активно, начинаем новое перемещение
            console.log('Starting move interaction');
            
            // Удаление старого стиля
            highlightedPolygons.forEach((polygon) => {
                polygon.setStyle(null);
            });

            // Подсвечиваем полигон красным
            feature.setStyle(createPolygonStyle(feature.getGeometry().getCoordinates()[0], 'move'));

            activeTranslateInteraction = new ol.interaction.Translate({
                features: new ol.Collection([feature])
            });

            map.addInteraction(activeTranslateInteraction);

            cancelButton.onclick = () => {
                console.log('Cancel button clicked');
                map.removeInteraction(activeTranslateInteraction);
                feature.setStyle(createPolygonStyle(feature.getGeometry().getCoordinates()[0]));
                activeTranslateInteraction = null;
                select.getFeatures().clear(); // Снять выделение
            };

            finishButton.onclick = () => {
                console.log('Finish button clicked');
                map.removeInteraction(activeTranslateInteraction);
                feature.setStyle(createPolygonStyle(feature.getGeometry().getCoordinates()[0]));
                activeTranslateInteraction = null;
                select.getFeatures().clear(); // Снять выделение
            };
        }
    } else {
        console.log('Ошибка перемещения: нет выбранных полигонов');
    }
});



  // Обработка правого клика мыши для удаления точки
map.on('pointermove', function(event) {
    if (event.dragging) return;

    const pixel = map.getPixelFromCoordinate(event.coordinate);
    const features = map.getFeaturesAtPixel(pixel);

    if (features.length > 0) {
        const feature = features[0];
        if (feature instanceof ol.Feature && feature.getGeometry() instanceof ol.geom.Point && isEditingMode) {
            console.log('Курсор наведен на точку: готова к удалению');
            // Сохраняем ссылку на точку для последующего удаления
            map.getTargetElement().setAttribute('data-remove-feature-id', feature.getId());
        }
    } else {
        // Если курсор не наведён на точку, удаляем атрибут
        map.getTargetElement().removeAttribute('data-remove-feature-id');
    }
});

map.on('contextmenu', function(event) {
    event.preventDefault();
    console.log('Нажат клик удаления');
    console.log('isEditingMode before deletion:', isEditingMode); // Логирование isEditingMode


    if (!isEditingMode) {
        console.log('Удаление точек разрешено только в режиме редактирования');
        return;
    }

    const pixel = map.getEventPixel(event.originalEvent);
    const featureId = map.getTargetElement().getAttribute('data-remove-feature-id');

    if (featureId) {
        // Найти точку с данным идентификатором и удалить её
        const featureToRemove = drawSource.getFeatureById(featureId);
        if (featureToRemove) {
            drawSource.removeFeature(featureToRemove);
            console.log('Точка удалена');
        } else {
            console.log('Ошибка удаления: не удалось найти точку для удаления');
        }
        map.getTargetElement().removeAttribute('data-remove-feature-id');
    } else {
        console.log('Ошибка удаления: не удалось найти точку по координатам');
    }
});




    </script>
