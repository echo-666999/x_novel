<?php

namespace App\Actions\Novels;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 空业务库仅初始化操作者；重复调用不改密码，不创建小说或 Provider 密钥。 */
final class InitializeNovelSystemAction
{
    public function handle(string $name, string $email, string $password): User
    {
        Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:12'],
        ])->validate();

        return DB::transaction(fn () => User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => $password]));
    }
}
