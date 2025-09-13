// mk.js
export default {
    weekdays: {
        shorthand: ["Пон", "Вто", "Сре", "Чет", "Пет", "Саб", "Нед"],
        longhand: ["Понеделник", "Вторник", "Среда", "Четврток", "Петок", "Сабота", "Недела"]
    },
    months: {
        shorthand: ["Јан", "Фев", "Мар", "Апр", "Мај", "Јун", "Јул", "Авг", "Сеп", "Окт", "Ное", "Дек"],
        longhand: ["Јануари", "Февруари", "Март", "Април", "Мај", "Јуни", "Јули", "Август", "Септември", "Октомври", "Ноември", "Декември"]
    },
    daysInMonth: [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31],
    today: "Денес",
    clear: "Исчисти",
    close: "Затвори",
    rangeSeparator: " до ",  // Important for date ranges
    weekAbbreviation: "Нед",
    scrollTitle: "Скролувај за да го смените времето",
    toggleTitle: "Кликнете за да го префрлите 12/24 часа форматот",
    // Added missing translations (most important ones):
    am: "AM",
    pm: "PM",
    ordinal: function(number, _dayOfMonth) {
        return number + "."; // Or your preferred ordinal suffix
    }
};