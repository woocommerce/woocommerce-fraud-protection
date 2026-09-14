type Rule = {
	id: number;
	action: string;
	value: string;
	type: string;
	created_at: string;
};

type DataViewsProps = {
	data: Rule[];
};

export function DataViews( { data }: DataViewsProps ) {
	return (
		<table aria-label="Rules">
			<tbody>
				{ data.map( ( rule ) => (
					<tr key={ rule.id }>
						<td>{ rule.action }</td>
						<td>{ rule.value }</td>
						<td>{ rule.type }</td>
						<td>{ rule.created_at }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}
