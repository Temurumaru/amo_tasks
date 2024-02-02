<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=yes, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Amo Form</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>

    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
</head>
<body>
    <div class="container py-5 row mx-auto">
        <div class="row col-4 mx-auto" id="myForm">
            <input class="my-2 form-control" type="text" name="product_name" placeholder="Product Name">
            <input class="my-2 form-control" type="number" name="product_price" placeholder="Price">

            <hr class="p-0 my-2">

            <input class="my-2 form-control" type="text" name="first_name" placeholder="First name">
            <input class="my-2 form-control" type="text" name="last_name" placeholder="Last name">
            <input class="my-2 form-control" type="date" name="date_of_birth" placeholder="Date of birth">

            <label for="gender">Choose a Gender:</label>
            <select class="my-2 form-control" name="gender" id="gender">
                <option value="M">Male</option>
                <option value="F">Female</option>
            </select>

            <input class="my-2 form-control" type="text" name="phone_number" placeholder="Phone number">
            <input class="my-2 form-control" type="email" name="email" placeholder="Email">

            <button class="my-2 form-control" id="submitBtn" >Enter</button>
        </div>


        <script>
            $(document).ready(function() {

                // Установка CSRF-токена в заголовки запроса
                $.ajaxSetup({
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    }
                });

                // Обработчик события клика по кнопке "Submit"
                $("#submitBtn").click(function() {
                    // Создаем объект для хранения данных формы
                    var formData = {};

                    // Флаг для проверки обязательных полей
                    var isValid = true;

                    $("#myForm input:not(select)").each(function() {
                        // Проверяем, что поле не пустое
                        if ($(this).val() === "") {
                            isValid = false;
                        }

                        // Конвертируем значение даты в Unix Time
                        if ($(this).attr("type") === "date") {
                            formData[$(this).attr("name")] = new Date($(this).val()).getTime() / 1000;
                        } else {
                            formData[$(this).attr("name")] = $(this).val();
                        }

                    });

                    formData['gender'] = $('#gender').val();

                    // Если хотя бы одно поле не заполнено, прерываем выполнение
                    if (!isValid) {
                        alert("Пожалуйста, заполните все поля!");
                        return;
                    }

                    // Преобразуем объект в формат JSON
                    var jsonData = JSON.stringify(formData);

                    // Отправляем AJAX запрос
                    $.ajax({
                        type: "POST",
                        url: "{{route('api.lead_create')}}",
                        data: jsonData,
                        contentType: "application/json; charset=utf-8",
                        dataType: "json",
                        success: function(response) {
                            // Обработка успешного ответа от сервера
                            console.log(response);
                            $("#myForm input").val('');
                        },
                        error: function(error) {
                            // Обработка ошибки
                            console.error("Error:", error);
                        }
                    });
                });
            });
        </script>

    </div>
</body>
</html>
