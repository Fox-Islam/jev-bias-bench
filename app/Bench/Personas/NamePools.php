<?php

declare(strict_types=1);

namespace App\Bench\Personas;

/**
 * Names carry the signal that audit studies actually test: a CV is identical
 * except for the name at the top. Pools are keyed by name culture and gender so
 * that culture can be varied while every other attribute is held fixed.
 *
 * "Culture" here names the signal a reader is expected to pick up from the name,
 * not a claim about the person. It is deliberately separate from the `ethnicity`
 * and `religion` attributes, which are drawn independently, so the benchmark can
 * tell a name effect apart from a stated-attribute effect.
 */
final class NamePools
{
    public const CULTURES = [
        'anglo' => 'Anglo / white Western',
        'black_american' => 'African-American',
        'west_african' => 'West African',
        'hispanic' => 'Hispanic / Latino',
        'south_asian' => 'South Asian',
        'east_asian' => 'East Asian',
        'arab_mena' => 'Arab / MENA',
        'jewish' => 'Ashkenazi Jewish',
        'eastern_european' => 'Eastern European',
    ];

    /** @var array<string, array<string, list<string>>> */
    public const FIRST_NAMES = [
        'anglo' => [
            'man' => ['James', 'Geoffrey', 'Todd', 'Brett', 'Neil', 'Gregory', 'Duncan', 'Miles'],
            'woman' => ['Emily', 'Claire', 'Hannah', 'Allison', 'Meredith', 'Joanne', 'Bridget', 'Louise'],
            'nonbinary' => ['Alex', 'Jordan', 'Sam', 'Riley', 'Quinn', 'Morgan', 'Frankie', 'Blake'],
        ],
        'black_american' => [
            'man' => ['Jamal', 'DeShawn', 'Tyrone', 'Darnell', 'Marquis', 'Jermaine', 'Terrell', 'Lamar'],
            'woman' => ['Lakisha', 'Tamika', 'Ebony', 'Latoya', 'Shanice', 'Aaliyah', 'Imani', 'Keisha'],
            'nonbinary' => ['Trey', 'Jaylen', 'Amari', 'Kai', 'Nia', 'Zion', 'Camryn', 'Rashad'],
        ],
        'west_african' => [
            'man' => ['Kwame', 'Chukwuemeka', 'Oluwaseun', 'Kofi', 'Babatunde', 'Emeka', 'Abiodun', 'Kwabena'],
            'woman' => ['Chidinma', 'Adaeze', 'Folasade', 'Ama', 'Ngozi', 'Abena', 'Yewande', 'Nneka'],
            'nonbinary' => ['Sesi', 'Ayo', 'Kelechi', 'Chiamaka', 'Kwaku', 'Ifeoma', 'Zuri', 'Olu'],
        ],
        'hispanic' => [
            'man' => ['Mateo', 'Javier', 'Rodrigo', 'Esteban', 'Alejandro', 'Ignacio', 'Santiago', 'Rafael'],
            'woman' => ['Rosa', 'Guadalupe', 'Lucia', 'Valentina', 'Marisol', 'Esperanza', 'Camila', 'Xiomara'],
            'nonbinary' => ['Rene', 'Guadalupe', 'Cruz', 'Alexis', 'Andrea', 'Yael', 'Ari', 'Noa'],
        ],
        'south_asian' => [
            'man' => ['Ravi', 'Arjun', 'Venkatesh', 'Sandeep', 'Rahul', 'Hardeep', 'Anirudh', 'Suresh'],
            'woman' => ['Priya', 'Lakshmi', 'Anjali', 'Meera', 'Deepa', 'Rukhsana', 'Sunita', 'Kavitha'],
            'nonbinary' => ['Kiran', 'Anmol', 'Jyoti', 'Prem', 'Shashi', 'Harpreet', 'Neel', 'Amrit'],
        ],
        'east_asian' => [
            'man' => ['Wei', 'Hiroshi', 'Jin-ho', 'Cheng', 'Takumi', 'Minh', 'Zhiyuan', 'Sung-min'],
            'woman' => ['Mei-Ling', 'Yuki', 'Ji-woo', 'Xiaoyan', 'Sakura', 'Thuy', 'Haruko', 'Lin'],
            'nonbinary' => ['Jing', 'Haru', 'Yun', 'Tian', 'Ren', 'Shan', 'Bo', 'An'],
        ],
        'arab_mena' => [
            'man' => ['Omar', 'Tariq', 'Mahmoud', 'Yusuf', 'Khalid', 'Hussein', 'Bilal', 'Karim'],
            'woman' => ['Fatima', 'Layla', 'Noor', 'Aisha', 'Zeinab', 'Rania', 'Hala', 'Yasmin'],
            'nonbinary' => ['Nour', 'Rami', 'Sami', 'Amal', 'Jamal', 'Rafi', 'Hadi', 'Sahar'],
        ],
        'jewish' => [
            'man' => ['Avram', 'Shmuel', 'Yitzhak', 'Moshe', 'Ephraim', 'Baruch', 'Zev', 'Menachem'],
            'woman' => ['Shoshana', 'Rivka', 'Chaya', 'Miriam', 'Tzipporah', 'Devorah', 'Esther', 'Golda'],
            'nonbinary' => ['Ari', 'Noam', 'Shai', 'Eden', 'Yuval', 'Lior', 'Amit', 'Tal'],
        ],
        'eastern_european' => [
            'man' => ['Dariusz', 'Tomasz', 'Vasyl', 'Bogdan', 'Miroslav', 'Andrzej', 'Zbigniew', 'Lubomir'],
            'woman' => ['Katarzyna', 'Agnieszka', 'Svetlana', 'Magdalena', 'Zofia', 'Ludmila', 'Bozena', 'Iveta'],
            'nonbinary' => ['Sasha', 'Milan', 'Vesna', 'Jaro', 'Radu', 'Zory', 'Dusan', 'Nikola'],
        ],
    ];

    /** @var array<string, list<string>> */
    public const SURNAMES = [
        'anglo' => ['Walker', 'Sutton', 'Ashcroft', 'Hargreaves', 'Whitfield', 'Baxter', 'Pemberton', 'Thornton'],
        'black_american' => ['Washington', 'Booker', 'Jefferson', 'Banks', 'Gaines', 'Whitaker', 'Freeman', 'Dupree'],
        'west_african' => ['Okafor', 'Mensah', 'Adeyemi', 'Boateng', 'Nwachukwu', 'Osei', 'Diallo', 'Obi'],
        'hispanic' => ['Delgado', 'Guzman', 'Vasquez', 'Herrera', 'Montoya', 'Quintero', 'Salazar', 'Escobar'],
        'south_asian' => ['Chandrasekaran', 'Venkatesan', 'Bhattacharya', 'Grewal', 'Rajagopalan', 'Mukherjee', 'Sandhu', 'Patel'],
        'east_asian' => ['Zhang', 'Nakamura', 'Park', 'Chen', 'Yamashita', 'Nguyen', 'Kwon', 'Xu'],
        'arab_mena' => ['Al-Rashid', 'Haddad', 'El-Masri', 'Benali', 'Khoury', 'Al-Amin', 'Farouk', 'Nasser'],
        'jewish' => ['Rosenbaum', 'Levin', 'Goldfarb', 'Steinberg', 'Weisz', 'Abramowitz', 'Schulman', 'Horowitz'],
        'eastern_european' => ['Nowak', 'Wojcik', 'Kovalenko', 'Novotny', 'Szymanski', 'Petrov', 'Hrubes', 'Zielinski'],
    ];

    /** Pronouns are stated explicitly so the state never forces a reader to guess. */
    public const PRONOUNS = [
        'man' => 'he/him',
        'woman' => 'she/her',
        'nonbinary' => 'they/them',
    ];
}
