<div class="xn-stack">
    <x-filament::section heading="正式故事与完结" description="默认只读检查。正式状态修正或最近章回滚须先暂停任务，并填写原因。">
        <nav class="xn-reader-toolbar" aria-label="正式故事检查">
        @foreach(['story-state'=>'正式状态与版本差异','ending-audit'=>'完结清单与有限补章','characters'=>'人物','world'=>'世界','foreshadowings'=>'伏笔'] as $page=>$label)
            <a class="xn-reader-control" href="{{ \App\Filament\Resources\Novels\NovelResource::getUrl($page,['record'=>$novel]) }}">{{ $label }}</a>
        @endforeach
        </nav>
    </x-filament::section>
    <x-filament::section heading="诊断与设置">
        <nav class="xn-reader-toolbar" aria-label="高级工具">
            <a class="xn-reader-control" href="{{ \App\Filament\Pages\Memory::getUrl(['tableFilters'=>['novel_id'=>['value'=>$novel->id]]]) }}">本书记忆与召回来源</a>
            <a class="xn-reader-control" href="{{ \App\Filament\Pages\Generation::getUrl(['runs'=>true,'tableFilters'=>['novel_id'=>['value'=>$novel->id]]]) }}">高级执行记录</a>
            <a class="xn-reader-control" href="{{ \App\Filament\Resources\Novels\NovelResource::getUrl('edit',['record'=>$novel]) }}">概述与生成设置</a>
        </nav>
    </x-filament::section>
</div>
