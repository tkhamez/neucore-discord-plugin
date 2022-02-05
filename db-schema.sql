
create table discord_accounts1
(
    character_id  int          null,
    player_id     int          not null,
    discord_id    bigint       null,
    member_status varchar(32)  not null,
    username      varchar(255) null,
    discriminator varchar(8)   null,
    created       datetime     null,
    updated       datetime     null,
    constraint discord_accounts1_character_id_uindex unique (character_id),
    constraint discord_accounts1_discord_id_uindex unique (discord_id),
    constraint discord_accounts1_player_id_uindex unique (player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

create index discord_accounts1_status_index on discord_accounts1 (member_status);
create index discord_accounts1_updated_index on discord_accounts1 (updated);
